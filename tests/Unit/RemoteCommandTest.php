<?php

use Symfony\Component\Process\Process;
use TackleRemote\Commands\RemoteCommand;
use TackleRemote\Support\RemoteState;

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
