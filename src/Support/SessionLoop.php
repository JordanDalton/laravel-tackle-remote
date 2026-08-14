<?php

namespace TackleRemote\Support;

use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Tackle\Contracts\CodingAgent;
use Tackle\Events\SessionEnded;
use Tackle\Events\SessionStarted;
use Tackle\Support\BudgetTracker;
use Tackle\Support\ConversationCompactor;
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

            $this->state->emit('user', ['text' => $message['text']]);
            $this->publishState('running');

            $this->runTurn($message['text']);

            $this->persistSession();
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

    private function runTurn(string $task): void
    {
        if ($this->compactor->shouldCompact($this->agent)) {
            $this->state->emit('status', ['text' => 'Compacting earlier conversation…']);
            $this->compactor->compact($this->agent);
        }

        try {
            $this->agent->stream($task)->each(function ($event) {
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
