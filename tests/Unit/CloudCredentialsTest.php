<?php

use TackleRemote\Support\CloudCredentials;

it('stores connector credentials with owner-only permissions', function () {
    $path = sys_get_temp_dir().'/tackle-cloud-credentials-'.uniqid().'/connector.json';
    $credentials = new CloudCredentials($path);
    $payload = [
        'token' => 'tkl_connector_secret',
        'connector_id' => 'connector-uuid',
        'heartbeat_url' => 'https://cloud.example/api/connector/heartbeat',
    ];

    try {
        $credentials->store($payload);

        expect($credentials->load())->toBe($payload)
            ->and(fileperms($path) & 0777)->toBe(0600);

        $credentials->forget();
        expect(file_exists($path))->toBeFalse();
    } finally {
        @unlink($path);
        @rmdir(dirname($path));
    }
});

it('rejects a malformed saved credential', function () {
    $path = sys_get_temp_dir().'/tackle-cloud-credentials-'.uniqid().'.json';
    file_put_contents($path, '{}');

    try {
        (new CloudCredentials($path))->load();
    } finally {
        @unlink($path);
    }
})->throws(RuntimeException::class);
