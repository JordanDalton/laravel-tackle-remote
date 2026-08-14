<?php

namespace TackleRemote\Support;

use FilesystemIterator;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tackle\Contracts\CodingAgent;
use Tackle\Events\SessionEnded;
use Tackle\Events\SessionStarted;
use Tackle\Support\BudgetTracker;
use Tackle\Support\ConversationCompactor;
use Tackle\Support\CustomCommands;
use Tackle\Support\ImageAttachments;
use Tackle\Support\PathGuard;
use Tackle\Support\SessionStore;
use Throwable;

/**
 * The agent side of tackle:remote: waits for messages in the inbox, runs each
 * as an agent turn (the same way ai:code does), and appends everything the
 * browser needs to render — text chunks, tool calls, budget — to the event
 * log. Runs until told to stop.
 */
class SessionLoop
{
    private bool $running = true;

    public function __construct(
        private readonly CodingAgent $agent,
        private readonly BudgetTracker $budget,
        private readonly SessionStore $sessions,
        private readonly ConversationCompactor $compactor,
        private readonly RemoteState $state,
        private readonly string $sessionName,
        private readonly int $pollIntervalMs = 400,
        private readonly ?\Closure $onIdle = null,
    ) {}

    public function stop(): void
    {
        $this->running = false;
    }

    public function run(): void
    {
        $this->resumeSession();
        $this->publishCommands();
        $this->publishFileIndex();

        SessionStarted::dispatch('tackle:remote', (string) config('tackle.provider', 'anthropic'), (string) config('tackle.model'));

        $this->publishState('idle');
        $this->state->emit('ready', ['session' => $this->sessionName]);

        while ($this->running) {
            $message = $this->state->popMessage();

            if ($message === null) {
                ($this->onIdle)?->__invoke();
                usleep($this->pollIntervalMs * 1000);

                continue;
            }

            if (($message['command'] ?? null) === 'clear') {
                $this->clearConversation();

                continue;
            }

            if (! isset($message['text'])) {
                continue;
            }

            $text = (string) $message['text'];
            $images = array_values(array_filter(array_map(
                fn ($id) => $this->state->attachmentPath((string) $id),
                (array) ($message['images'] ?? []),
            )));

            if (($parsed = CustomCommands::parse($text)) !== null) {
                $this->runSlashCommand($parsed[0], $parsed[1], $text, $images);
                $this->publishState('idle');

                continue;
            }

            $this->state->emit('user', [
                'text' => $text,
                ...($images !== [] ? ['images' => array_map('basename', $images)] : []),
            ]);
            $this->publishState('running');

            $this->runTurn($text, $images);

            $this->persistSession();
            $this->publishFileIndex();
            $this->publishState('idle');
        }

        SessionEnded::dispatch(
            'tackle:remote',
            $this->budget->inputTokens(),
            $this->budget->outputTokens(),
            round($this->budget->estimatedCost(), 4),
        );

        $this->publishState('stopped');
    }

    /**
     * The same built-ins ai:code's REPL has, plus project commands from
     * .tackle/commands/*.md. /help never reaches here — the UI renders it
     * from the published command list.
     *
     * @param  array<int, string>  $images
     */
    private function runSlashCommand(string $name, string $arguments, string $original, array $images): void
    {
        if ($name === 'clear') {
            $this->clearConversation();

            return;
        }

        if ($name === 'compact') {
            $this->state->emit('user', ['text' => $original]);
            $compacted = $this->compactor->compact($this->agent);
            $this->state->emit('status', ['text' => $compacted ? 'Conversation compacted.' : 'Nothing to compact yet.']);
            $this->persistSession();

            return;
        }

        $rendered = app(CustomCommands::class)->render($name, $arguments);

        if ($rendered === null) {
            $this->state->emit('error', [
                'text' => "Unknown command /{$name} — see /help, or add .tackle/commands/{$name}.md to define it.",
            ]);

            return;
        }

        // The transcript shows what was typed; the agent gets the template.
        $this->state->emit('user', ['text' => $original]);
        $this->publishState('running');
        $this->runTurn($rendered, $images);
        $this->persistSession();
        $this->publishFileIndex();
    }

    /**
     * @param  array<int, string>  $imagePaths
     */
    private function runTurn(string $task, array $imagePaths = []): void
    {
        if ($this->compactor->shouldCompact($this->agent)) {
            $this->state->emit('status', ['text' => 'Compacting earlier conversation…']);
            $this->compactor->compact($this->agent);
        }

        // @-mentioned images on the server attach as vision input, exactly
        // as they do when dropped into the ai:code terminal.
        [$task, $mentioned, $unreadable] = ImageAttachments::extract($task, $this->workspaceRoot());

        foreach ($unreadable as $path) {
            $this->state->emit('status', ['text' => "Could not read image: {$path}"]);
        }

        $attachments = [
            ...array_map(fn (string $path) => Image::fromPath($path), $imagePaths),
            ...$mentioned,
        ];

        try {
            $this->agent->stream($task, $attachments)->each(function ($event) {
                if ($event instanceof TextDelta) {
                    $this->state->emit('text', ['delta' => $event->delta]);

                    return;
                }

                if ($event instanceof ToolCall) {
                    $this->state->emit('tool_call', [
                        'tool' => $event->toolCall->name,
                        'arguments' => (array) $event->toolCall->arguments,
                    ]);

                    return;
                }

                if ($event instanceof ToolResult) {
                    $this->state->emit('tool_result', [
                        'tool' => $event->toolResult->name,
                        'summary' => mb_substr((string) ($event->toolResult->result ?? ''), 0, 400),
                    ]);

                    return;
                }

                if ($event instanceof StreamEnd) {
                    $this->budget->record($event->usage->promptTokens, $event->usage->completionTokens);

                    if ($this->budget->overBudget()) {
                        $this->state->emit('status', ['text' => sprintf(
                            'Budget limit reached ($%.4f / $%.2f).',
                            $this->budget->estimatedCost(),
                            $this->budget->budgetUsd(),
                        )]);
                    }
                }
            });
        } catch (Throwable $e) {
            $this->state->emit('error', ['text' => $e->getMessage()]);
        }

        $this->state->emit('turn_done', []);
    }

    /**
     * The web equivalent of /clear in ai:code: forget the agent's
     * conversation, delete the persisted session, and truncate the event
     * log so every connected client resets. Budget spend is real money
     * already spent, so it survives the clear.
     */
    private function clearConversation(): void
    {
        if (method_exists($this->agent, 'forgetConversation')) {
            $this->agent->forgetConversation();
        }

        if ($this->sessions->enabled()) {
            $this->sessions->forget($this->sessionName);
        }

        $this->state->clearEvents();
        $this->state->clearAttachments();
        $this->state->emit('cleared', ['session' => $this->sessionName]);
        $this->publishState('idle');
    }

    /**
     * Publish the slash-command roster for the composer's autocomplete:
     * built-ins plus project commands, described by their template's first line.
     */
    private function publishCommands(): void
    {
        $commands = [
            ['name' => 'clear', 'description' => 'Forget the conversation and start fresh'],
            ['name' => 'compact', 'description' => 'Summarize older history to free up context'],
            ['name' => 'help', 'description' => 'List available commands'],
        ];

        foreach (app(CustomCommands::class)->all() as $name => $path) {
            $firstLine = trim((string) strtok((string) @file_get_contents($path), "\n"));

            $commands[] = [
                'name' => $name,
                'description' => mb_substr(ltrim($firstLine, "# \t"), 0, 80),
            ];
        }

        $this->state->putCommands($commands);
    }

    /**
     * Publish the workspace file index for @-mention autocomplete. git
     * ls-files is fast and gitignore-aware (so .env, vendor/, storage/
     * never appear); non-repo workspaces fall back to a capped scan with
     * the same exclusions the ai:code terminal completion uses.
     */
    private function publishFileIndex(): void
    {
        $root = $this->workspaceRoot();
        $files = [];

        exec('git -C '.escapeshellarg($root).' ls-files --cached --others --exclude-standard 2>/dev/null', $files, $exit);

        if ($exit !== 0 || $files === []) {
            $files = $this->scanWorkspace($root);
        }

        $this->state->putFiles(array_slice($files, 0, 5000));
    }

    /**
     * @return array<int, string>
     */
    private function scanWorkspace(string $root): array
    {
        $excluded = ['vendor', 'node_modules', 'storage', '.git'];

        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            fn ($file) => ! in_array($file->getFilename(), $excluded, true),
        ));

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            }

            if (count($files) >= 5000) {
                break;
            }
        }

        sort($files);

        return $files;
    }

    private function workspaceRoot(): string
    {
        return app(PathGuard::class)->workspace();
    }

    private function resumeSession(): void
    {
        if (! $this->sessions->enabled() || ! method_exists($this->agent, 'replaceConversation')) {
            return;
        }

        $messages = $this->sessions->load($this->sessionName);

        if ($messages !== []) {
            $this->agent->replaceConversation($messages);
            $this->state->emit('status', ['text' => sprintf('Resumed session "%s" (%d messages).', $this->sessionName, count($messages))]);
        }
    }

    private function persistSession(): void
    {
        if ($this->sessions->enabled() && method_exists($this->agent, 'messages')) {
            $this->sessions->save($this->sessionName, $this->agent->messages());
        }
    }

    private function publishState(string $status): void
    {
        $this->state->putState([
            'status' => $status,
            'session' => $this->sessionName,
            'model' => (string) config('tackle.model'),
            'budget' => [
                'spent_usd' => round($this->budget->estimatedCost(), 4),
                'limit_usd' => $this->budget->budgetUsd(),
                'input_tokens' => $this->budget->inputTokens(),
                'output_tokens' => $this->budget->outputTokens(),
            ],
            'updated_at' => microtime(true),
        ]);
    }
}
