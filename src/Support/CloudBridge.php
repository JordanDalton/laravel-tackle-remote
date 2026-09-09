<?php

namespace TackleRemote\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

class CloudBridge
{
    private int $cursor = 0;

    private string $epoch;

    /** @var list<string> */
    private array $acknowledged = [];

    /** @var list<string> */
    private array $seen = [];

    /**
     * @param  array{token: string, connector_id: string, heartbeat_url: string, sync_url?: string}  $credentials
     * @param  array<string, mixed>  $identity
     */
    public function __construct(
        private readonly CloudConnectorClient $client,
        private readonly array $credentials,
        private readonly RemoteState $remote,
        private readonly array $identity,
    ) {
        $saved = json_decode((string) @file_get_contents($this->statePath()), true);
        $this->cursor = is_array($saved) ? max(0, (int) ($saved['cursor'] ?? 0)) : 0;
        $this->epoch = is_array($saved) && is_string($saved['epoch'] ?? null)
            ? $saved['epoch']
            : (string) Str::ulid();
        $this->acknowledged = is_array($saved['acknowledged'] ?? null)
            ? array_values(array_filter($saved['acknowledged'], 'is_string'))
            : [];
        $this->seen = is_array($saved['seen'] ?? null)
            ? array_values(array_filter($saved['seen'], 'is_string'))
            : [];
    }

    public function sync(): void
    {
        $batch = $this->remote->eventsAfter($this->cursor);

        if ($batch['cursor'] < $this->cursor) {
            $this->cursor = 0;
            $this->epoch = (string) Str::ulid();
            $batch = $this->remote->eventsAfter(0);
        }

        $fresh = array_slice($batch['events'], 0, 500);
        $nextCursor = $this->cursor + count($fresh);
        $events = [];
        foreach ($fresh as $offset => $event) {
            $payload = $event;
            unset($payload['type'], $payload['at']);
            $events[] = [
                'client_id' => $this->epoch.':'.($this->cursor + $offset + 1),
                'type' => (string) ($event['type'] ?? 'event'),
                'at' => is_numeric($event['at'] ?? null) ? $event['at'] : microtime(true),
                'payload' => $payload,
            ];
        }

        $result = $this->client->sync($this->credentials, [
            'version' => $this->identity['version'] ?? null,
            'capabilities' => $this->identity['capabilities'] ?? [],
            'metadata' => $this->identity['metadata'] ?? [],
            'state' => $this->remote->state(),
            'question' => $this->remote->pendingQuestion(),
            'events' => $events,
            'acknowledged_commands' => $this->acknowledged,
        ]);

        $this->acknowledged = [];
        $this->cursor = $nextCursor;

        foreach ($result['commands'] as $command) {
            $id = is_string($command['id'] ?? null) ? $command['id'] : null;

            if ($id === null) {
                continue;
            }

            if (! in_array($id, $this->seen, true)) {
                $this->dispatch($command);
                $this->seen[] = $id;
                $this->seen = array_slice($this->seen, -500);
            }

            $this->acknowledged[] = $id;
        }

        $this->persist();
    }

    /** @param array<string, mixed> $command */
    private function dispatch(array $command): void
    {
        $type = (string) ($command['type'] ?? '');
        $payload = is_array($command['payload'] ?? null) ? $command['payload'] : [];

        if ($type === 'clear') {
            $this->remote->pushCommand('clear');

            return;
        }

        if ($type === 'answer') {
            $id = (string) ($payload['id'] ?? '');
            if ($id !== '') {
                $this->remote->answer($id, $payload['value'] ?? 'no');
            }

            return;
        }

        if ($type !== 'message') {
            return;
        }

        $images = [];
        foreach ((array) ($payload['attachments'] ?? []) as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $bytes = base64_decode((string) ($attachment['content_base64'] ?? ''), true);
            if (! is_string($bytes)) {
                continue;
            }

            try {
                $images[] = $this->remote->storeAttachment((string) ($attachment['name'] ?? 'image.jpg'), $bytes);
            } catch (InvalidArgumentException) {
                // Invalid Cloud attachment metadata must not stop text delivery.
            }
        }

        $this->remote->pushMessage((string) ($payload['text'] ?? ''), $images);
    }

    private function persist(): void
    {
        file_put_contents($this->statePath(), json_encode([
            'cursor' => $this->cursor,
            'epoch' => $this->epoch,
            'acknowledged' => $this->acknowledged,
            'seen' => $this->seen,
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function statePath(): string
    {
        return $this->remote->dir().'/cloud-bridge.json';
    }
}
