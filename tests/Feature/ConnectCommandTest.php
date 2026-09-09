<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->credentialsPath = sys_get_temp_dir().'/tackle-connect-'.uniqid().'/connector.json';
    config()->set('tackle-remote.connector_credentials_path', $this->credentialsPath);
});

afterEach(function () {
    @unlink($this->credentialsPath);
    @rmdir(dirname($this->credentialsPath));
});

it('enrolls once, stores the credential, and heartbeats on later starts', function () {
    Http::fake([
        'https://cloud.example/api/connectors/enroll' => Http::response([
            'data' => [
                'token' => 'tkl_connector_secret',
                'connector_id' => 'connector-uuid',
                'heartbeat_url' => 'https://cloud.example/api/connector/heartbeat',
            ],
        ], 201),
        'https://cloud.example/api/connector/heartbeat' => Http::response([
            'data' => ['status' => 'connected'],
        ]),
    ]);

    $first = Artisan::call('tackle:connect', [
        '--url' => 'https://cloud.example/api/connectors/enroll',
        '--code' => 'tkl_enroll_once',
        '--once' => true,
    ]);

    expect($first)->toBe(0)
        ->and(file_exists($this->credentialsPath))->toBeTrue();

    $second = Artisan::call('tackle:connect', [
        '--url' => 'https://cloud.example/api/connectors/enroll',
        '--code' => 'tkl_enroll_once',
        '--once' => true,
    ]);

    expect($second)->toBe(0);

    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->url() === 'https://cloud.example/api/connectors/enroll'
        && $request['code'] === 'tkl_enroll_once');
    Http::assertSent(fn ($request) => $request->url() === 'https://cloud.example/api/connector/heartbeat'
        && $request->hasHeader('Authorization', 'Bearer tkl_connector_secret'));
});

it('fails safely when cloud revokes the connector credential', function () {
    $directory = dirname($this->credentialsPath);
    mkdir($directory, 0700, true);
    file_put_contents($this->credentialsPath, json_encode([
        'token' => 'tkl_connector_revoked',
        'connector_id' => 'connector-uuid',
        'heartbeat_url' => 'https://cloud.example/api/connector/heartbeat',
    ]));

    Http::fake([
        'https://cloud.example/api/connector/heartbeat' => Http::response([
            'message' => 'Invalid connector token.',
        ], 401),
    ]);

    expect(Artisan::call('tackle:connect', ['--once' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('rejected or revoked');
});

it('can forget a saved connector credential', function () {
    $directory = dirname($this->credentialsPath);
    mkdir($directory, 0700, true);
    file_put_contents($this->credentialsPath, '{}');

    expect(Artisan::call('tackle:connect', ['--forget' => true]))->toBe(0)
        ->and(file_exists($this->credentialsPath))->toBeFalse();
});
