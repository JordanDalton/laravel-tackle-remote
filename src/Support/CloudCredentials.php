<?php

namespace TackleRemote\Support;

use RuntimeException;

class CloudCredentials
{
    public function __construct(private readonly string $path) {}

    /** @return array{token: string, connector_id: string, heartbeat_url: string}|null */
    public function load(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = file_get_contents($this->path);
        $credentials = is_string($contents) ? json_decode($contents, true) : null;

        if (
            ! is_array($credentials)
            || ! is_string($credentials['token'] ?? null)
            || ! is_string($credentials['connector_id'] ?? null)
            || ! is_string($credentials['heartbeat_url'] ?? null)
        ) {
            throw new RuntimeException(
                "The Tackle Cloud credential at {$this->path} is unreadable. Run tackle:connect --forget and enroll again.",
            );
        }

        return [
            'token' => $credentials['token'],
            'connector_id' => $credentials['connector_id'],
            'heartbeat_url' => $credentials['heartbeat_url'],
        ];
    }

    /** @param array{token: string, connector_id: string, heartbeat_url: string} $credentials */
    public function store(array $credentials): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the connector credential directory at {$directory}.");
        }

        $encoded = json_encode($credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded) || file_put_contents($this->path, $encoded.PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Could not store the Tackle Cloud credential at {$this->path}.");
        }

        chmod($this->path, 0600);
    }

    public function forget(): void
    {
        if (is_file($this->path) && ! unlink($this->path)) {
            throw new RuntimeException("Could not remove the Tackle Cloud credential at {$this->path}.");
        }
    }

    public function path(): string
    {
        return $this->path;
    }
}
