<?php

use Laravel\Ai\Files\Image;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
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

it('clears the conversation on a clear command', function () {
    $dir = sys_get_temp_dir().'/tackle-remote-loop-'.uniqid();
    $state = new RemoteState($dir);

    $state->emit('user', ['text' => 'old history']);
    $state->pushCommand('clear');

    $loop = new SessionLoop(
        new LoopTestIdleAgent,
        app(BudgetTracker::class),
        app(SessionStore::class),
        app(ConversationCompactor::class),
        $state,
        'loop-clear-test',
        pollIntervalMs: 1,
        onIdle: function () use (&$loop) {
            $loop->stop(); // Inbox drained — the command was processed.
        },
    );

    $loop->run();

    $events = collect($state->eventsAfter(0)['events'])->pluck('type');

    expect($events)->not->toContain('user')
        ->and($events)->toContain('cleared');

    exec('rm -rf '.escapeshellarg($dir));
});

class LoopTestRecordingAgent implements CodingAgent
{
    use Promptable;

    public static ?string $prompt = null;

    public static array $attachments = [];

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

    public function stream(mixed $prompt, array $attachments = [], mixed $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        self::$prompt = is_string($prompt) ? $prompt : null;
        self::$attachments = $attachments;

        return new StreamableAgentResponse(
            'fake',
            function () {
                yield from [];
            },
            new Meta('fake', 'fake-model'),
        );
    }
}

it('passes uploaded images to the agent as attachments', function () {
    $dir = sys_get_temp_dir().'/tackle-remote-loop-'.uniqid();
    $state = new RemoteState($dir);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $id = $state->storeAttachment('photo.png', $png);
    $state->pushMessage('what is in this photo?', [$id, 'missing.png']);

    LoopTestRecordingAgent::$prompt = null;
    LoopTestRecordingAgent::$attachments = [];

    $loop = new SessionLoop(
        new LoopTestRecordingAgent,
        app(BudgetTracker::class),
        app(SessionStore::class),
        app(ConversationCompactor::class),
        $state,
        'loop-image-test',
        pollIntervalMs: 1,
        onIdle: function () use (&$loop) {
            $loop->stop();
        },
    );

    $loop->run();

    $userEvent = collect($state->eventsAfter(0)['events'])->firstWhere('type', 'user');

    expect(LoopTestRecordingAgent::$prompt)->toBe('what is in this photo?')
        ->and(LoopTestRecordingAgent::$attachments)->toHaveCount(1)
        ->and(LoopTestRecordingAgent::$attachments[0])->toBeInstanceOf(Image::class)
        ->and($userEvent['images'])->toBe([$id]);

    exec('rm -rf '.escapeshellarg($dir));
});

it('expands custom slash commands and publishes the roster', function () {
    $workspace = sys_get_temp_dir().'/tackle-remote-ws-'.uniqid();
    mkdir($workspace.'/.tackle/commands', 0755, true);
    file_put_contents($workspace.'/.tackle/commands/greet.md', "Greet someone warmly\n\nSay hello to \$ARGUMENTS and nothing else.");
    file_put_contents($workspace.'/readme.md', 'hi');
    config()->set('tackle.workspace', $workspace);

    $dir = sys_get_temp_dir().'/tackle-remote-loop-'.uniqid();
    $state = new RemoteState($dir);
    $state->pushMessage('/greet Jordan');

    LoopTestRecordingAgent::$prompt = null;

    $loop = new SessionLoop(
        new LoopTestRecordingAgent,
        app(BudgetTracker::class),
        app(SessionStore::class),
        app(ConversationCompactor::class),
        $state,
        'loop-slash-test',
        pollIntervalMs: 1,
        onIdle: function () use (&$loop) {
            $loop->stop();
        },
    );

    $loop->run();

    $userEvent = collect($state->eventsAfter(0)['events'])->firstWhere('type', 'user');

    expect(LoopTestRecordingAgent::$prompt)->toBe("Greet someone warmly\n\nSay hello to Jordan and nothing else.")
        ->and($userEvent['text'])->toBe('/greet Jordan')
        ->and(collect($state->commands())->pluck('name'))->toContain('clear', 'compact', 'help', 'greet')
        ->and(collect($state->commands())->firstWhere('name', 'greet')['description'])->toBe('Greet someone warmly')
        ->and($state->searchFiles('read'))->toContain('readme.md');

    exec('rm -rf '.escapeshellarg($dir).' '.escapeshellarg($workspace));
});

it('reports unknown slash commands without running a turn', function () {
    $workspace = sys_get_temp_dir().'/tackle-remote-ws-'.uniqid();
    mkdir($workspace, 0755, true);
    config()->set('tackle.workspace', $workspace);

    $dir = sys_get_temp_dir().'/tackle-remote-loop-'.uniqid();
    $state = new RemoteState($dir);
    $state->pushMessage('/nope do things');

    LoopTestRecordingAgent::$prompt = null;

    $loop = new SessionLoop(
        new LoopTestRecordingAgent,
        app(BudgetTracker::class),
        app(SessionStore::class),
        app(ConversationCompactor::class),
        $state,
        'loop-unknown-test',
        pollIntervalMs: 1,
        onIdle: function () use (&$loop) {
            $loop->stop();
        },
    );

    $loop->run();

    $error = collect($state->eventsAfter(0)['events'])->firstWhere('type', 'error');

    expect(LoopTestRecordingAgent::$prompt)->toBeNull()
        ->and($error['text'])->toContain('Unknown command /nope');

    exec('rm -rf '.escapeshellarg($dir).' '.escapeshellarg($workspace));
});

it('treats path-like slashes as ordinary messages', function () {
    $workspace = sys_get_temp_dir().'/tackle-remote-ws-'.uniqid();
    mkdir($workspace, 0755, true);
    config()->set('tackle.workspace', $workspace);

    $dir = sys_get_temp_dir().'/tackle-remote-loop-'.uniqid();
    $state = new RemoteState($dir);
    $state->pushMessage('/usr/local/bin has a broken symlink');

    LoopTestRecordingAgent::$prompt = null;

    $loop = new SessionLoop(
        new LoopTestRecordingAgent,
        app(BudgetTracker::class),
        app(SessionStore::class),
        app(ConversationCompactor::class),
        $state,
        'loop-path-test',
        pollIntervalMs: 1,
        onIdle: function () use (&$loop) {
            $loop->stop();
        },
    );

    $loop->run();

    expect(LoopTestRecordingAgent::$prompt)->toBe('/usr/local/bin has a broken symlink');

    exec('rm -rf '.escapeshellarg($dir).' '.escapeshellarg($workspace));
});
