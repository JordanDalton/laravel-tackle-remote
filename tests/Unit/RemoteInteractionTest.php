<?php

use TackleRemote\Support\RemoteInteraction;
use TackleRemote\Support\RemoteState;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/tackle-remote-interaction-'.uniqid();
    $this->state = new RemoteState($this->dir);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * Spawn a background PHP process that plays the browser: waits for the
 * pending question to appear, then writes the given answer for it.
 */
function answerFromBrowser(string $dir, string $value): void
{
    $script = sprintf(
        '$d=%s; while (! file_exists($d."/question.json")) usleep(5000); '.
        '$q=json_decode(file_get_contents($d."/question.json"), true); '.
        'file_put_contents($d."/answers/".$q["id"].".json", json_encode(["value" => %s]));',
        var_export($dir, true),
        var_export($value, true),
    );

    exec(sprintf('%s -r %s > /dev/null 2>&1 &', escapeshellarg(PHP_BINARY), escapeshellarg($script)));
}

it('resolves a confirm from a browser answer', function () {
    answerFromBrowser($this->dir, 'yes');

    $interaction = new RemoteInteraction($this->state, timeoutSeconds: 5, pollIntervalMs: 10);

    expect($interaction->confirm('Deploy?'))->toBeTrue()
        ->and($interaction->deniedCount())->toBe(0);
});

it('counts a browser denial', function () {
    answerFromBrowser($this->dir, 'no');

    $interaction = new RemoteInteraction($this->state, timeoutSeconds: 5, pollIntervalMs: 10);

    expect($interaction->confirm('Deploy?'))->toBeFalse()
        ->and($interaction->deniedCount())->toBe(1);
});

it('denies a confirm on timeout and clears the question', function () {
    $interaction = new RemoteInteraction($this->state, timeoutSeconds: 0, pollIntervalMs: 10);

    expect($interaction->confirm('Deploy?'))->toBeFalse()
        ->and($interaction->deniedCount())->toBe(1)
        ->and($this->state->pendingQuestion())->toBeNull();
});

it('supports always-allow answers', function () {
    answerFromBrowser($this->dir, 'always');

    $interaction = new RemoteInteraction($this->state, timeoutSeconds: 5, pollIntervalMs: 10);

    expect($interaction->confirmWithAlways('Run `php artisan migrate`?'))->toBe('always');
});

it('resolves a choose from a browser answer', function () {
    answerFromBrowser($this->dir, 'b');

    $interaction = new RemoteInteraction($this->state, timeoutSeconds: 5, pollIntervalMs: 10);

    expect($interaction->choose('Pick one', ['a' => 'Option A', 'b' => 'Option B']))->toBe('b');
});

it('tells the agent to decide for itself when a choose times out', function () {
    $interaction = new RemoteInteraction($this->state, timeoutSeconds: 0, pollIntervalMs: 10);

    $result = $interaction->choose('Pick one', ['a' => 'Option A', 'b' => 'Option B']);

    expect($result)->toContain('Select the option you judge best')
        ->and($interaction->isInteractive())->toBeTrue();
});

it('normalizes list-style options for the browser', function () {
    $interaction = new RemoteInteraction($this->state, timeoutSeconds: 0, pollIntervalMs: 10);

    $interaction->choose('Pick one', ['Alpha', 'Beta']);

    $events = $this->state->eventsAfter(0)['events'];
    $question = collect($events)->firstWhere('type', 'question');

    expect($question['options'])->toBe(['Alpha' => 'Alpha', 'Beta' => 'Beta']);
});
