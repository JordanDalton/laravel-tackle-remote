<?php

use Symfony\Component\Process\Process;
use TackleRemote\Commands\RemoteCommand;
use TackleRemote\Support\RemoteState;

function testableRemoteCommand(): RemoteCommand
{
    $command = new class extends RemoteCommand
    {
        public function normalizedPublicUrl(mixed $url): ?string
        {
            return $this->normalizePublicUrl($url);
        }

        public function advertisedPairingUrl(string $code, string $host, int $port, ?string $publicUrl = null): string
        {
            return $this->pairingUrl($code, $host, $port, $publicUrl);
        }

        public function advertisedPairingPreviewUrl(string $code, string $publicUrl): string
        {
            return $this->pairingPreviewUrl($code, $publicUrl);
        }
    };

    $command->setLaravel(app());

    return $command;
}

it('advertises a reverse-proxied public URL including its path', function () {
    $command = testableRemoteCommand();
    $publicUrl = $command->normalizedPublicUrl('https://example.com/tackle-remote');

    expect($publicUrl)->toBe('https://example.com/tackle-remote/')
        ->and($command->advertisedPairingUrl('fresh-code', '127.0.0.1', 8787, $publicUrl))
        ->toBe('https://example.com/tackle-remote/?pair=fresh-code')
        ->and($command->advertisedPairingPreviewUrl('fresh-code', $publicUrl))
        ->toBe('https://example.com/tackle-remote/pairing?pair=fresh-code');
});

it('keeps the local bind URL when no public URL is configured', function () {
    $command = testableRemoteCommand();

    expect($command->normalizedPublicUrl(null))->toBeNull()
        ->and($command->advertisedPairingUrl('fresh-code', '127.0.0.1', 8787))
        ->toBe('http://127.0.0.1:8787/?pair=fresh-code');
});

it('rejects public URLs containing unsafe or ambiguous components', function (string $url) {
    testableRemoteCommand()->normalizedPublicUrl($url);
})->throws(InvalidArgumentException::class)
    ->with([
        'relative URL' => ['example.com/tackle-remote'],
        'query string' => ['https://example.com/tackle-remote?pair=old'],
        'fragment' => ['https://example.com/tackle-remote#pair'],
        'credentials' => ['https://user:secret@example.com/tackle-remote'],
    ]);

it('discards HTTP server output so request logs cannot block it', function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

    expect($socket)->not->toBeFalse($errorMessage);

    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    $port = (int) substr((string) strrchr((string) $address, ':'), 1);
    $dir = sys_get_temp_dir().'/tackle-remote-command-'.uniqid();
    $state = new RemoteState($dir);

    $command = new class extends RemoteCommand
    {
        public function startServer(string $host, int $port, string $secret, RemoteState $state): ?Process
        {
            return $this->startHttpServer($host, $port, $secret, $state);
        }
    };
    $command->setLaravel(app());

    $server = $command->startServer('127.0.0.1', $port, bin2hex(random_bytes(32)), $state);

    try {
        expect($server)
            ->toBeInstanceOf(Process::class)
            ->and($server->isOutputDisabled())->toBeTrue()
            ->and($server->isRunning())->toBeTrue();
    } finally {
        $server?->stop();

        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }
});
