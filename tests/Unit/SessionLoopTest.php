<?php

use Laravel\Ai\Promptable;
use Tackle\Contracts\CodingAgent;
use Tackle\Support\BudgetTracker;
use Tackle\Support\ConversationCompactor;
use Tackle\Support\SessionStore;
use TackleRemote\Support\RemoteState;
use TackleRemote\Support\SessionLoop;

class LoopTestIdleAgent implements CodingAgent
{
    use Promptable;

    public function instructions(): string
    {
        return '';
    }

    public function tools(): iterable
    {
        return [];
    }

    public function messages(): iterable
    {
        return [];
    }
}

it('invokes the onIdle callback on idle ticks', function () {
    $dir = sys_get_temp_dir().'/tackle-remote-loop-'.uniqid();
    $state = new RemoteState($dir);
    $ticks = 0;

    $loop = new SessionLoop(
        new LoopTestIdleAgent,
        app(BudgetTracker::class),
        app(SessionStore::class),
        app(ConversationCompactor::class),
        $state,
        'loop-test',
        pollIntervalMs: 1,
        onIdle: function () use (&$ticks, &$loop) {
            $ticks++;

            if ($ticks >= 3) {
                $loop->stop();
            }
        },
    );

    $loop->run();

    expect($ticks)->toBeGreaterThanOrEqual(3)
        ->and($state->state()['status'])->toBe('stopped');

    exec('rm -rf '.escapeshellarg($dir));
});
