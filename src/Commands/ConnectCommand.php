<?php

namespace TackleRemote\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use TackleRemote\Support\CloudConnectorClient;
use TackleRemote\Support\CloudCredentials;
use Throwable;

class ConnectCommand extends Command
{
    protected $signature = 'tackle:connect
        {--url= : Tackle Cloud connector enrollment URL}
        {--code= : Single-use connector enrollment code}
        {--name= : Name shown for this deployment}
        {--once : Enroll or heartbeat once, then exit}
        {--forget : Remove the saved connector credential and exit}';

    protected $description = 'Keep this Laravel deployment connected to Tackle Cloud';

    private bool $running = true;

    public function handle(CloudConnectorClient $client): int
    {
        $credentials = new CloudCredentials(
            (string) config('tackle-remote.connector_credentials_path'),
        );

        if ((bool) $this->option('forget')) {
            $credentials->forget();
            $this->components->info('Saved Tackle Cloud connector credential removed.');

            return self::SUCCESS;
        }

        try {
            $stored = $credentials->load();

            if ($stored === null) {
                $url = trim((string) ($this->option('url') ?: config('tackle-remote.cloud_url')));
                $code = trim((string) $this->option('code'));

                if ($url === '' || $code === '') {
                    $this->components->error(
                        'This deployment is not enrolled. Copy the tackle:connect command from Tackle Cloud.',
                    );

                    return self::FAILURE;
                }

                $stored = $client->enroll($url, $code, $this->identity());
                $credentials->store($stored);
                $this->components->info('Deployment enrolled with Tackle Cloud.');
            } elseif ($this->option('code')) {
                $this->components->warn(
                    'A connector credential is already saved; the single-use enrollment code was ignored.',
                );
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->trapSignals();
        $delay = max(5, (int) config('tackle-remote.connector_heartbeat_seconds', 30));
        $announced = false;

        while ($this->running) {
            try {
                $client->heartbeat($stored, $this->heartbeatState());

                if (! $announced) {
                    $this->components->info(
                        "Connected to Tackle Cloud as {$stored['connector_id']}.",
                    );
                    $announced = true;
                }
            } catch (RequestException $exception) {
                $status = $exception->response->status();
                $this->components->error(
                    in_array($status, [401, 403], true)
                        ? 'The connector credential was rejected or revoked. Run tackle:connect --forget, then enroll again.'
                        : "Tackle Cloud heartbeat failed with HTTP {$status}; retrying.",
                );

                if ((bool) $this->option('once') || in_array($status, [401, 403], true)) {
                    return self::FAILURE;
                }
            } catch (Throwable $exception) {
                $this->components->error("Tackle Cloud is unavailable: {$exception->getMessage()} Retrying.");

                if ((bool) $this->option('once')) {
                    return self::FAILURE;
                }
            }

            if ((bool) $this->option('once')) {
                return self::SUCCESS;
            }

            sleep($delay);
        }

        return self::SUCCESS;
    }

    /** @return array{name: string, version: string|null, capabilities: list<string>, metadata: array<string, string>} */
    private function identity(): array
    {
        return [
            'name' => trim((string) ($this->option('name') ?: config('tackle-remote.connector_name')))
                ?: ((string) config('app.name', 'Laravel')).' · '.((string) app()->environment()),
            'version' => $this->packageVersion(),
            'capabilities' => ['chat', 'files', 'images', 'approvals'],
            'metadata' => [
                'app' => (string) config('app.name', 'Laravel'),
                'environment' => (string) app()->environment(),
                'hostname' => gethostname() ?: 'unknown',
            ],
        ];
    }

    /** @return array{version: string|null, capabilities: list<string>, metadata: array<string, string>} */
    private function heartbeatState(): array
    {
        $identity = $this->identity();
        unset($identity['name']);

        return $identity;
    }

    private function packageVersion(): ?string
    {
        try {
            return InstalledVersions::getPrettyVersion('jordandalton/laravel-tackle-remote');
        } catch (Throwable) {
            return null;
        }
    }

    private function trapSignals(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->running = false;
            });
        }
    }
}
