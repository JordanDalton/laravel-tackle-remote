<?php

namespace TackleRemote\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Tackle\Contracts\CodingAgent;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Support\BudgetTracker;
use Tackle\Support\ConversationCompactor;
use Tackle\Support\SessionStore;
use TackleRemote\Support\CloudBridge;
use TackleRemote\Support\CloudConnectorClient;
use TackleRemote\Support\CloudCredentials;
use TackleRemote\Support\ConnectorRestartSignal;
use TackleRemote\Support\RemoteInteraction;
use TackleRemote\Support\RemoteState;
use TackleRemote\Support\SessionLoop;
use Throwable;

class ConnectCommand extends Command
{
    protected $signature = 'tackle:connect
        {--url= : Tackler connector enrollment URL}
        {--code= : Single-use connector enrollment code}
        {--name= : Name shown for this deployment}
        {--session=cloud : Persistent Tackle session used by Cloud clients}
        {--once : Enroll or heartbeat once, then exit}
        {--forget : Remove the saved connector credential and exit}';

    protected $description = 'Keep this Laravel deployment connected to Tackler';

    private bool $running = true;

    private ?SessionLoop $loop = null;

    public function handle(CloudConnectorClient $client): int
    {
        $credentials = new CloudCredentials(
            (string) config('tackle-remote.connector_credentials_path'),
        );

        if ((bool) $this->option('forget')) {
            $credentials->forget();
            $this->components->info('Saved Tackler connector credential removed.');

            return self::SUCCESS;
        }

        try {
            $stored = $credentials->load();

            if ($stored === null) {
                $url = trim((string) ($this->option('url') ?: config('tackle-remote.cloud_url')));
                $code = trim((string) $this->option('code'));

                if ($url === '' || $code === '') {
                    $this->components->error(
                        'This deployment is not enrolled. Copy the tackle:connect command from Tackler.',
                    );

                    return self::FAILURE;
                }

                $stored = $client->enroll($url, $code, $this->identity());
                $credentials->store($stored);
                $this->components->info('Deployment enrolled with Tackler.');
            } elseif ($this->option('code')) {
                $this->components->warn(
                    'A connector credential is already saved; the single-use enrollment code was ignored.',
                );
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('once')) {
            try {
                $client->heartbeat($stored, $this->heartbeatState());
                $this->components->info("Connected to Tackler as {$stored['connector_id']}.");
            } catch (RequestException $exception) {
                $status = $exception->response->status();
                $this->components->error(
                    in_array($status, [401, 403], true)
                        ? 'The connector credential was rejected or revoked. Run tackle:connect --forget, then enroll again.'
                        : "Tackler heartbeat failed with HTTP {$status}; retrying.",
                );

                return self::FAILURE;
            } catch (Throwable $exception) {
                $this->components->error("Tackler is unavailable: {$exception->getMessage()}");

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $session = trim((string) $this->option('session')) ?: 'cloud';
        $state = new RemoteState(rtrim((string) config('tackle-remote.storage_path'), '/').'/'.$session);
        $state->putIdentity([
            'api' => 1,
            'name' => (string) config('app.name', 'Laravel'),
            'environment' => (string) app()->environment(),
            'project' => basename(rtrim(base_path(), '/')),
            'session' => $session,
        ]);
        $identity = $this->heartbeatState();
        $bridge = new CloudBridge($client, $stored, $state, $identity);
        $restartSignal = new ConnectorRestartSignal(
            (string) config('tackle-remote.connector_restart_signal_path'),
        );
        $nextSyncAt = 0.0;
        $lastError = '';
        $sync = function () use ($bridge, $restartSignal, &$nextSyncAt, &$lastError): void {
            if ($restartSignal->requested()) {
                $this->components->info('Connector restart requested; shutting down gracefully.');
                $this->loop?->stop();

                return;
            }

            if (microtime(true) < $nextSyncAt) {
                return;
            }

            $nextSyncAt = microtime(true) + max(0.25, (float) config('tackle-remote.connector_sync_seconds', 1));

            try {
                $bridge->sync();
                $lastError = '';
            } catch (Throwable $exception) {
                if ($exception->getMessage() !== $lastError) {
                    $this->components->warn("Tackler sync failed: {$exception->getMessage()} Retrying.");
                    $lastError = $exception->getMessage();
                }
            }
        };

        $this->laravel->instance(InteractionPolicy::class, new RemoteInteraction(
            $state,
            (int) config('tackle-remote.answer_timeout', 600),
            onWait: $sync,
        ));
        $this->loop = new SessionLoop(
            $this->laravel->make(CodingAgent::class),
            $this->laravel->make(BudgetTracker::class),
            $this->laravel->make(SessionStore::class),
            $this->laravel->make(ConversationCompactor::class),
            $state,
            $session,
            (int) config('tackle-remote.poll_interval_ms', 400),
            onIdle: $sync,
        );

        $this->components->info("Connected to Tackler as {$stored['connector_id']}.");
        $this->components->info("Cloud chat is ready — session \"{$session}\".");
        $this->trapSignals();
        $this->loop->run();

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
                $this->loop?->stop();
            });
        }
    }
}
