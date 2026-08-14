<?php

namespace TackleRemote\Support;

use Illuminate\Support\Str;

/**
 * The file protocol shared by the agent process (php artisan tackle:remote)
 * and the HTTP server it spawns. Everything the two processes say to each
 * other goes through this directory:
 *
 *   events.jsonl     append-only log the browser polls (chat, tool calls, status)
 *   inbox/*.json     user messages waiting for the agent, oldest first
 *   question.json    the approval question currently awaiting an answer, if any
 *   answers/*.json   answers to questions, keyed by question id
 *   state.json       session status + budget snapshot, rewritten atomically
 *
 * The HTTP router deliberately does not boot Laravel, so this class must stay
 * usable from plain PHP: no facades, no container. Only the constructor
 * arguments tie it to a location.
 */
class RemoteState
{
    public function __construct(private readonly string $dir)
    {
        foreach ([$this->dir, $this->dir.'/inbox', $this->dir.'/answers'] as $path) {
            if (! is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /*
    |----------------------------------------------------------------------
    | Event log (agent writes, browser reads)
    |----------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     */
    public function emit(string $type, array $data = []): void
    {
        $line = json_encode(
            ['type' => $type, 'at' => microtime(true), ...$data],
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        file_put_contents($this->dir.'/events.jsonl', $line."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Events after the given line cursor, plus the new cursor.
     *
     * @return array{events: array<int, array<string, mixed>>, cursor: int}
     */
    public function eventsAfter(int $cursor): array
    {
        $path = $this->dir.'/events.jsonl';

        if (! is_file($path)) {
            return ['events' => [], 'cursor' => 0];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $fresh = array_slice($lines, $cursor);

        $events = array_values(array_filter(array_map(
            fn (string $line) => json_decode($line, true),
            $fresh,
        ), 'is_array'));

        return ['events' => $events, 'cursor' => count($lines)];
    }

    /*
    |----------------------------------------------------------------------
    | Inbox (browser writes, agent reads)
    |----------------------------------------------------------------------
    */

    public function pushMessage(string $text): string
    {
        return $this->pushToInbox(['text' => $text]);
    }

    /**
     * Queue a control command (e.g. 'clear') for the agent process.
     */
    public function pushCommand(string $command): string
    {
        return $this->pushToInbox(['command' => $command]);
    }

    /**
     * Claim the oldest queued inbox entry, removing it from the inbox.
     * Entries carry either 'text' (a user message) or 'command'.
     *
     * @return array{id: string, text?: string, command?: string}|null
     */
    public function popMessage(): ?array
    {
        $files = glob($this->dir.'/inbox/*.json') ?: [];

        if ($files === []) {
            return null;
        }

        sort($files); // ULID filenames sort chronologically.

        $message = json_decode((string) file_get_contents($files[0]), true);
        unlink($files[0]);

        return is_array($message) && isset($message['id']) && (isset($message['text']) || isset($message['command']))
            ? $message
            : null;
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function pushToInbox(array $payload): string
    {
        $id = (string) Str::ulid();

        file_put_contents(
            $this->dir."/inbox/{$id}.json",
            json_encode(['id' => $id, ...$payload], JSON_UNESCAPED_SLASHES),
        );

        return $id;
    }

    /**
     * Truncate the event log. Clients notice their cursor moving backward
     * and reset. Emit a fresh event right after so they have something to
     * render.
     */
    public function clearEvents(): void
    {
        file_put_contents($this->dir.'/events.jsonl', '', LOCK_EX);
    }

    /*
    |----------------------------------------------------------------------
    | Questions & answers (agent asks, browser answers)
    |----------------------------------------------------------------------
    */

    /**
     * @param  array<string, string>  $options  answer value => label
     */
    public function ask(string $label, array $options, ?string $hint = null): string
    {
        $id = (string) Str::ulid();

        file_put_contents($this->dir.'/question.json', json_encode([
            'id' => $id,
            'label' => $label,
            'hint' => $hint,
            'options' => $options,
        ], JSON_UNESCAPED_SLASHES));

        return $id;
    }

    /**
     * @return array{id: string, label: string, hint: ?string, options: array<string, string>}|null
     */
    public function pendingQuestion(): ?array
    {
        $path = $this->dir.'/question.json';

        if (! is_file($path)) {
            return null;
        }

        $question = json_decode((string) file_get_contents($path), true);

        return is_array($question) ? $question : null;
    }

    public function answer(string $questionId, string|array $value): void
    {
        file_put_contents(
            $this->dir.'/answers/'.basename($questionId).'.json',
            json_encode(['value' => $value], JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * The answer for a question, if one has arrived. Consuming it clears both
     * the answer file and the pending question.
     */
    public function takeAnswer(string $questionId): string|array|null
    {
        $path = $this->dir.'/answers/'.basename($questionId).'.json';

        if (! is_file($path)) {
            return null;
        }

        $answer = json_decode((string) file_get_contents($path), true);
        unlink($path);
        @unlink($this->dir.'/question.json');

        return is_array($answer) && array_key_exists('value', $answer) ? $answer['value'] : null;
    }

    public function clearQuestion(): void
    {
        @unlink($this->dir.'/question.json');
    }

    /*
    |----------------------------------------------------------------------
    | Session state (agent writes, browser reads)
    |----------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $state
     */
    public function putState(array $state): void
    {
        $tmp = $this->dir.'/state.json.tmp';

        file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_SLASHES));
        rename($tmp, $this->dir.'/state.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $path = $this->dir.'/state.json';

        if (! is_file($path)) {
            return ['status' => 'starting'];
        }

        $state = json_decode((string) file_get_contents($path), true);

        return is_array($state) ? $state : ['status' => 'starting'];
    }
}
