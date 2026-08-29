<?php

use TackleRemote\Support\AccessGuard;
use TackleRemote\Support\RemoteState;

/**
 * Contract tests for server/router.php — the auth gate and the eight endpoints
 * every client codes against.
 *
 * These boot the real thing: `php -S` running the shipped router, driven over
 * real HTTP. The router is a procedural script that reads superglobals, so
 * anything less than a live request would be testing a rewrite of it rather
 * than the file that ships. It stays untouched; the tests come to it.
 */

/**
 * @return array{url: string, dir: string, secret: string, process: resource, pipes: array}
 */
function remote(): array
{
    $dir = sys_get_temp_dir().'/tackle-remote-router-'.uniqid();
    $secret = bin2hex(random_bytes(16));

    // Touch the state dir into existence before the server races us for it.
    new RemoteState($dir);

    $port = freePort();
    $root = dirname(__DIR__, 2);

    $env = [
        'TACKLE_REMOTE_DIR' => $dir,
        'TACKLE_REMOTE_SECRET' => $secret,
        'TACKLE_REMOTE_LIFETIME' => '43200',
        'TACKLE_REMOTE_SPA' => $root.'/resources/spa.html',
        'TACKLE_REMOTE_AUTOLOAD' => $root.'/vendor/autoload.php',
        'PATH' => getenv('PATH'),
    ];

    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", $root.'/server/router.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $env,
    );

    $url = "http://127.0.0.1:{$port}";

    // Connect without sending anything: an HTTP probe would be an
    // unauthenticated request, and those count against the lockout limit.
    $up = false;

    for ($i = 0; $i < 100; $i++) {
        $probe = curl_init("http://127.0.0.1:{$port}");
        curl_setopt_array($probe, [CURLOPT_CONNECT_ONLY => true, CURLOPT_CONNECTTIMEOUT => 1]);
        $up = curl_exec($probe) !== false;
        curl_close($probe);

        if ($up) {
            break;
        }

        usleep(50_000);
    }

    if (! $up) {
        // Say why, rather than leaving every assertion to fail mysteriously.
        $stderr = stream_get_contents($pipes[2]) ?: '(no output)';

        throw new RuntimeException("The router did not start on port {$port}: {$stderr}");
    }

    return ['url' => $url, 'dir' => $dir, 'secret' => $secret, 'process' => $process, 'pipes' => $pipes];
}

function stopRemote(array $remote): void
{
    foreach ($remote['pipes'] as $pipe) {
        @fclose($pipe);
    }

    if (is_resource($remote['process'])) {
        proc_terminate($remote['process']);
        proc_close($remote['process']);
    }

    // Fifteen tests, fifteen state directories — clean up after ourselves.
    removeDirectory($remote['dir']);
}

function removeDirectory(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($dir);
}

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) explode(':', $name)[1];
}

/**
 * @return array{status: int, body: string, headers: list<string>}
 */
function routerRequest(string $url, string $method = 'GET', ?array $json = null, ?string $cookie = null): array
{
    $headers = ['Accept: application/json'];

    if ($cookie !== null) {
        $headers[] = 'Cookie: '.AccessGuard::COOKIE_NAME.'='.$cookie;
    }

    $handle = curl_init($url);
    $responseHeaders = [];

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [...$headers, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HEADERFUNCTION => function ($handle, string $header) use (&$responseHeaders) {
            $responseHeaders[] = trim($header);

            return strlen($header);
        },
    ]);

    if ($json !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, (string) json_encode($json));
    }

    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'headers' => $responseHeaders];
}

function sessionCookie(array $response): ?string
{
    foreach ($response['headers'] as $header) {
        if (preg_match('/^Set-Cookie:\s*'.AccessGuard::COOKIE_NAME.'=([^;]+)/i', $header, $match)) {
            return $match[1];
        }
    }

    return null;
}

function pair(array $remote): string
{
    $guard = new AccessGuard($remote['dir'], $remote['secret'], 43200);
    $code = $guard->issuePairingCode();

    $response = routerRequest($remote['url'].'/api/poll?pair='.$code);
    $cookie = sessionCookie($response);

    expect($cookie)->not->toBeNull();

    return (string) $cookie;
}

// ---------------------------------------------------------------------------
// The auth gate
// ---------------------------------------------------------------------------

it('refuses an unpaired request to any endpoint', function () {
    $remote = remote();

    try {
        foreach (['/', '/api/poll', '/api/commands', '/api/files'] as $path) {
            expect(routerRequest($remote['url'].$path)['status'])->toBe(403);
        }
    } finally {
        stopRemote($remote);
    }
});

it('claims a pairing code and issues a session cookie', function () {
    $remote = remote();

    try {
        expect(pair($remote))->toBeString()->not->toBeEmpty();
    } finally {
        stopRemote($remote);
    }
});

it('only lets a pairing code be claimed once', function () {
    $remote = remote();

    try {
        $guard = new AccessGuard($remote['dir'], $remote['secret'], 43200);
        $code = $guard->issuePairingCode();

        expect(routerRequest($remote['url'].'/api/poll?pair='.$code)['status'])->toBe(200);

        // A pairing link in a screenshot, a shell history, or a chat log is
        // spent the moment it is used once.
        expect(routerRequest($remote['url'].'/api/poll?pair='.$code)['status'])->toBe(403);
    } finally {
        stopRemote($remote);
    }
});

it('rejects a pairing code it never issued', function () {
    $remote = remote();

    try {
        expect(routerRequest($remote['url'].'/api/poll?pair=not-a-real-code')['status'])->toBe(403);
    } finally {
        stopRemote($remote);
    }
});

it('authenticates later requests by cookie alone', function () {
    $remote = remote();

    try {
        $cookie = pair($remote);

        expect(routerRequest($remote['url'].'/api/commands', cookie: $cookie)['status'])->toBe(200);
    } finally {
        stopRemote($remote);
    }
});

it('rejects a cookie that was not signed with the secret', function () {
    $remote = remote();

    try {
        $forged = (new AccessGuard($remote['dir'], 'a-different-secret', 43200))->mintCookie();

        expect(routerRequest($remote['url'].'/api/poll', cookie: $forged)['status'])->toBe(403);
    } finally {
        stopRemote($remote);
    }
});

it('locks an address out after repeated failures', function () {
    $remote = remote();

    try {
        // The limit is 10 failures in a 60-second window.
        for ($i = 0; $i < 10; $i++) {
            routerRequest($remote['url'].'/api/poll?pair=wrong-'.$i);
        }

        expect(routerRequest($remote['url'].'/api/poll?pair=wrong-again')['status'])->toBe(429);
    } finally {
        stopRemote($remote);
    }
});

it('honours a valid cookie even while the address is locked out', function () {
    $remote = remote();

    try {
        $cookie = pair($remote);

        for ($i = 0; $i < 12; $i++) {
            routerRequest($remote['url'].'/api/poll?pair=wrong-'.$i);
        }

        // A signed cookie cannot be brute-forced, so someone spamming bad
        // codes must not be able to lock out the paired device.
        expect(routerRequest($remote['url'].'/api/poll', cookie: $cookie)['status'])->toBe(200);
    } finally {
        stopRemote($remote);
    }
});

// ---------------------------------------------------------------------------
// The endpoints a client codes against
// ---------------------------------------------------------------------------

it('polls events, state, and any pending question', function () {
    $remote = remote();

    try {
        $cookie = pair($remote);
        $body = json_decode(routerRequest($remote['url'].'/api/poll?after=0', cookie: $cookie)['body'], true);

        expect($body)->toHaveKeys(['events', 'state', 'question']);
    } finally {
        stopRemote($remote);
    }
});

it('queues a message for the agent', function () {
    $remote = remote();

    try {
        $cookie = pair($remote);

        $response = routerRequest($remote['url'].'/api/message', 'POST', ['text' => 'fix the bug'], $cookie);

        expect($response['status'])->toBe(200)
            ->and(glob($remote['dir'].'/inbox/*.json'))->toHaveCount(1);
    } finally {
        stopRemote($remote);
    }
});

it('refuses an empty message', function () {
    $remote = remote();

    try {
        $cookie = pair($remote);

        expect(routerRequest($remote['url'].'/api/message', 'POST', ['text' => '  '], $cookie)['status'])
            ->toBe(422);
    } finally {
        stopRemote($remote);
    }
});

it('records an answer to a pending question', function () {
    $remote = remote();

    try {
        $cookie = pair($remote);

        $response = routerRequest($remote['url'].'/api/answer', 'POST', ['id' => 'q1', 'value' => 'yes'], $cookie);

        expect($response['status'])->toBe(200)
            ->and(is_file($remote['dir'].'/answers/q1.json'))->toBeTrue();
    } finally {
        stopRemote($remote);
    }
});

it('requires both an id and a value to answer', function () {
    $remote = remote();

    try {
        $cookie = pair($remote);

        expect(routerRequest($remote['url'].'/api/answer', 'POST', ['id' => 'q1'], $cookie)['status'])->toBe(422)
            ->and(routerRequest($remote['url'].'/api/answer', 'POST', ['value' => 'yes'], $cookie)['status'])->toBe(422);
    } finally {
        stopRemote($remote);
    }
});

it('serves the file index and command list', function () {
    $remote = remote();

    try {
        $cookie = pair($remote);

        expect(json_decode(routerRequest($remote['url'].'/api/files?q=', cookie: $cookie)['body'], true))
            ->toHaveKey('files')
            ->and(json_decode(routerRequest($remote['url'].'/api/commands', cookie: $cookie)['body'], true))
            ->toHaveKey('commands');
    } finally {
        stopRemote($remote);
    }
});

it('404s an unknown path rather than falling through', function () {
    $remote = remote();

    try {
        $cookie = pair($remote);

        expect(routerRequest($remote['url'].'/api/nope', cookie: $cookie)['status'])->toBe(404);
    } finally {
        stopRemote($remote);
    }
});
