<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->credentialsPath = sys_get_temp_dir().'/tackle-connect-'.uniqid().'/connector.json';
    $this->restartSignalPath = dirname($this->credentialsPath).'/connect.restart';
    config()->set('tackle-remote.connector_credentials_path', $this->credentialsPath);
    config()->set('tackle-remote.connector_restart_signal_path', $this->restartSignalPath);
});

afterEach(function () {
    @unlink($this->credentialsPath);
    @unlink($this->restartSignalPath);
    @rmdir(dirname($this->credentialsPath));
});

it('broadcasts a connector restart signal', function () {
    expect(Artisan::call('tackle:connect:restart'))->toBe(0)
        ->and(file_exists($this->restartSignalPath))->toBeTrue()
        ->and(file_get_contents($this->restartSignalPath))->not->toBe('')
        ->and(Artisan::output())->toContain('restart requested');
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

    Http::assertSentCount(4);
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

it('replaces a rejected saved credential when a fresh enrollment code is supplied', function () {
    $directory = dirname($this->credentialsPath);
    mkdir($directory, 0700, true);
    file_put_contents($this->credentialsPath, json_encode([
        'token' => 'tkl_connector_revoked',
        'connector_id' => 'old-connector-uuid',
        'heartbeat_url' => 'https://cloud.example/api/connector/heartbeat',
    ]));

    Http::fake(function ($request) {
        if ($request->url() === 'https://cloud.example/api/connectors/enroll') {
            return Http::response([
                'data' => [
                    'token' => 'tkl_connector_replacement',
                    'connector_id' => 'new-connector-uuid',
                    'heartbeat_url' => 'https://cloud.example/api/connector/heartbeat',
                ],
            ], 201);
        }

        if ($request->hasHeader('Authorization', 'Bearer tkl_connector_revoked')) {
            return Http::response(['message' => 'Invalid connector token.'], 401);
        }

        return Http::response(['data' => ['status' => 'connected']]);
    });

    expect(Artisan::call('tackle:connect', [
        '--url' => 'https://cloud.example/api/connectors/enroll',
        '--code' => 'tkl_enroll_fresh',
        '--once' => true,
    ]))->toBe(0)
        ->and(Artisan::output())->toContain('deployment re-enrolled with Tackler');

    $stored = json_decode((string) file_get_contents($this->credentialsPath), true);

    expect($stored['token'])->toBe('tkl_connector_replacement')
        ->and($stored['connector_id'])->toBe('new-connector-uuid');

    Http::assertSent(fn ($request) => $request->url() === 'https://cloud.example/api/connectors/enroll'
        && $request['code'] === 'tkl_enroll_fresh');
    Http::assertSent(fn ($request) => $request->url() === 'https://cloud.example/api/connector/heartbeat'
        && $request->hasHeader('Authorization', 'Bearer tkl_connector_replacement'));
});

it('can forget a saved connector credential', function () {
    $directory = dirname($this->credentialsPath);
    mkdir($directory, 0700, true);
    file_put_contents($this->credentialsPath, '{}');

    expect(Artisan::call('tackle:connect', ['--forget' => true]))->toBe(0)
        ->and(file_exists($this->credentialsPath))->toBeFalse()
        ->and(file_exists($this->restartSignalPath))->toBeTrue()
        ->and(Artisan::output())->toContain('asked to restart');
});
