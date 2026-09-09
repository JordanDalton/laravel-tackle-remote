<?php

use Illuminate\Support\Facades\Http;
use TackleRemote\Support\CloudBridge;
use TackleRemote\Support\CloudConnectorClient;
use TackleRemote\Support\RemoteState;

it('uploads local events and dispatches cloud messages exactly once', function () {
    $dir = sys_get_temp_dir().'/tackle-cloud-bridge-'.uniqid();
    $remote = new RemoteState($dir);
    $remote->emit('ready', ['session' => 'cloud']);
    $image = base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

    Http::fakeSequence('https://cloud.example/api/connector/sync')
        ->push(['data' => ['commands' => [[
            'id' => '30fbb69e-25f3-4d66-b2f6-e10db8262715',
            'type' => 'message',
            'payload' => [
                'text' => 'Inspect this',
                'attachments' => [['name' => 'screen.png', 'content_base64' => $image]],
            ],
        ]]]])
        ->push(['data' => ['commands' => [[
            'id' => '30fbb69e-25f3-4d66-b2f6-e10db8262715',
            'type' => 'message',
            'payload' => ['text' => 'Inspect this'],
        ]]]]);

    $bridge = new CloudBridge(app(CloudConnectorClient::class), [
        'token' => 'secret',
        'connector_id' => 'connector',
        'heartbeat_url' => 'https://cloud.example/api/connector/heartbeat',
        'sync_url' => 'https://cloud.example/api/connector/sync',
    ], $remote, ['capabilities' => ['chat']]);

    $bridge->sync();
    $bridge->sync();

    $message = $remote->popMessage();
    expect($message['text'])->toBe('Inspect this')
        ->and($message['images'])->toHaveCount(1)
        ->and($remote->popMessage())->toBeNull();

    Http::assertSent(fn ($request) => data_get($request->data(), 'events.0.type') === 'ready');
    Http::assertSent(fn ($request) => in_array(
        '30fbb69e-25f3-4d66-b2f6-e10db8262715',
        $request['acknowledged_commands'] ?? [],
        true,
    ));

    exec('rm -rf '.escapeshellarg($dir));
});

it('uploads large event histories in bounded batches', function () {
    $dir = sys_get_temp_dir().'/tackle-cloud-bridge-'.uniqid();
    $remote = new RemoteState($dir);

    foreach (range(1, 501) as $number) {
        $remote->emit('status', ['text' => "Event {$number}"]);
    }

    Http::fake(['https://cloud.example/api/connector/sync' => Http::response([
        'data' => ['commands' => []],
    ])]);
    $bridge = new CloudBridge(app(CloudConnectorClient::class), [
        'token' => 'secret',
        'connector_id' => 'connector',
        'heartbeat_url' => 'https://cloud.example/api/connector/heartbeat',
        'sync_url' => 'https://cloud.example/api/connector/sync',
    ], $remote, []);

    $bridge->sync();
    $bridge->sync();

    $counts = collect(Http::recorded())->map(fn (array $exchange) => count($exchange[0]['events']))->all();
    expect($counts)->toBe([500, 1]);

    exec('rm -rf '.escapeshellarg($dir));
});
