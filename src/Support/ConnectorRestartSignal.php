<?php

namespace TackleRemote\Support;

use RuntimeException;

final class ConnectorRestartSignal
{
    private ?string $baseline;

    public function __construct(private readonly string $path)
    {
        $this->baseline = $this->value();
    }

    public function request(): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the connector restart signal directory: {$directory}");
        }

        $value = sprintf('%.6F:%s', microtime(true), bin2hex(random_bytes(8)));
        $temporary = $this->path.'.tmp.'.getmypid();

        if (file_put_contents($temporary, $value, LOCK_EX) === false || ! rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException("Could not write the connector restart signal: {$this->path}");
        }
    }

    public function requested(): bool
    {
        return $this->value() !== $this->baseline;
    }

    private function value(): ?string
    {
        if (! is_file($this->path)) {
            return null;
        }

        $value = file_get_contents($this->path);

        return is_string($value) ? $value : null;
    }
}
