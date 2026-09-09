<?php

namespace TackleRemote\Support;

use Illuminate\Http\Client\Factory;
use RuntimeException;

class CloudConnectorClient
{
    public function __construct(private readonly Factory $http) {}

    /**
     * @param  array{name: string, version?: string|null, capabilities?: list<string>, metadata?: array<string, mixed>}  $identity
     * @return array{token: string, connector_id: string, heartbeat_url: string}
     */
    public function enroll(string $url, string $code, array $identity): array
    {
        $response = $this->http
            ->asJson()
            ->acceptJson()
            ->timeout(15)
            ->post($url, ['code' => $code, ...$identity])
            ->throw();

        $credentials = $response->json('data');

        if (
            ! is_array($credentials)
            || ! is_string($credentials['token'] ?? null)
            || ! is_string($credentials['connector_id'] ?? null)
            || ! is_string($credentials['heartbeat_url'] ?? null)
        ) {
            throw new RuntimeException('Tackle Cloud returned an invalid connector enrollment response.');
        }

        return [
            'token' => $credentials['token'],
            'connector_id' => $credentials['connector_id'],
            'heartbeat_url' => $credentials['heartbeat_url'],
        ];
    }

    /**
     * @param  array{token: string, connector_id: string, heartbeat_url: string}  $credentials
     * @param  array{version?: string|null, capabilities?: list<string>, metadata?: array<string, mixed>}  $state
     */
    public function heartbeat(array $credentials, array $state): void
    {
        $this->http
            ->asJson()
            ->acceptJson()
            ->withToken($credentials['token'])
            ->timeout(15)
            ->post($credentials['heartbeat_url'], $state)
            ->throw();
    }
}
