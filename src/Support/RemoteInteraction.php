<?php

namespace TackleRemote\Support;

use Tackle\Contracts\InteractionPolicy;

/**
 * Answers tool questions through the browser instead of the terminal. The
 * question is published to the shared state directory, the UI shows it as an
 * approval sheet, and this class polls for the tapped answer.
 *
 * A human is on the other end, so isInteractive() is true — but they may be
 * away from their phone. An unanswered confirmation times out to a denial
 * (never an approval), and the timeout is counted like any other denial.
 *
 * Mirrors TerminalInteraction's confirmWithAlways() so core tools that
 * discover it via method_exists offer "always allow" here too.
 */
class RemoteInteraction implements InteractionPolicy
{
    private int $denied = 0;

    public function __construct(
        private readonly RemoteState $state,
        private readonly int $timeoutSeconds = 600,
        private readonly int $pollIntervalMs = 200,
    ) {}

    public function confirm(string $label, bool $default = true, ?string $hint = null): bool
    {
        $answer = $this->askAndWait($label, ['yes' => 'Yes', 'no' => 'No'], $hint);

        if ($answer === null) {
            $this->denied++;

            return false;
        }

        $confirmed = $answer === 'yes';

        if (! $confirmed) {
            $this->denied++;
        }

        return $confirmed;
    }

    /**
     * Returns 'yes' | 'no' | 'always'. Not part of the InteractionPolicy
     * contract — core tools discover it via method_exists, exactly as they do
     * on TerminalInteraction.
     */
    public function confirmWithAlways(string $label, ?string $hint = null): string
    {
        $answer = $this->askAndWait($label, [
            'no' => 'Deny',
            'yes' => 'Allow once',
            'always' => 'Always allow',
        ], $hint);

        if ($answer === null || $answer === 'no') {
            $this->denied++;

            return 'no';
        }

        return $answer;
    }

    public function choose(string $question, array $options, bool $multiple = false): string
    {
        // Options may be a plain list or value => label; normalize to both.
        $normalized = [];

        foreach ($options as $key => $label) {
            $value = is_int($key) ? (string) $label : (string) $key;
            $normalized[$value] = (string) $label;
        }

        $answer = $this->askAndWait($question, $normalized, $multiple ? 'Select all that apply.' : null, $multiple);

        if ($answer === null) {
            return 'The user did not answer in time. Select the option you judge best, state which you chose and why, then continue without asking again.';
        }

        return is_array($answer) ? implode(', ', $answer) : (string) $answer;
    }

    public function isInteractive(): bool
    {
        return true;
    }

    public function deniedCount(): int
    {
        return $this->denied;
    }

    /**
     * @param  array<string, string>  $options
     */
    private function askAndWait(string $label, array $options, ?string $hint, bool $multiple = false): string|array|null
    {
        $id = $this->state->ask($label, $options, $hint);

        $this->state->emit('question', [
            'id' => $id,
            'label' => $label,
            'hint' => $hint,
            'options' => $options,
            'multiple' => $multiple,
        ]);

        $deadline = microtime(true) + $this->timeoutSeconds;

        while (microtime(true) < $deadline) {
            $answer = $this->state->takeAnswer($id);

            if ($answer !== null) {
                $this->state->emit('answered', ['id' => $id, 'value' => $answer]);

                return $answer;
            }

            usleep($this->pollIntervalMs * 1000);
        }

        $this->state->clearQuestion();
        $this->state->emit('answered', ['id' => $id, 'value' => null, 'timed_out' => true]);

        return null;
    }
}
