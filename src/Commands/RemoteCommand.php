<?php

namespace TackleRemote\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Tackle\Contracts\CodingAgent;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Support\BudgetTracker;
use Tackle\Support\ConversationCompactor;
use Tackle\Support\SessionStore;
use TackleRemote\Support\AccessGuard;
use TackleRemote\Support\RemoteInteraction;
use TackleRemote\Support\RemoteState;
use TackleRemote\Support\SessionLoop;
use TackleRemote\Support\TerminalQr;
use Throwable;

class RemoteCommand extends Command
{
    protected $signature = 'tackle:remote
        {--host= : Bind address (default from config; use 0.0.0.0 to allow your LAN)}
        {--port= : Port (default from config)}
        {--session=web : Session name — transcripts persist under this name}';

    protected $description = 'Serve a browser UI for Tackle — drive the agent from any device on your network';

    private ?Process $server = null;

    private ?SessionLoop $loop = null;

    public function handle(): int
    {
        $host = (string) ($this->option('host') ?: config('tackle-remote.host', '127.0.0.1'));
        $port = (int) ($this->option('port') ?: config('tackle-remote.port', 8787));
        $session = (string) $this->option('session');

        $state = new RemoteState(
            rtrim((string) config('tackle-remote.storage_path'), '/').'/'.$session,
        );

        $state->putIdentity($this->identity($session));

        // The signing secret lives only in memory and the child's env — never
        // on disk. Everything derived from it dies with this process.
        $secret = bin2hex(random_bytes(32));

        $guard = new AccessGuard(
            $state->dir(),
            $secret,
            (int) config('tackle-remote.session_lifetime', 43200),
        );

        // The browser answers questions from here on — bind before the agent
        // (and its tools) resolve, so every ConfirmAction/AskUser goes to the UI.
        $this->laravel->instance(InteractionPolicy::class, new RemoteInteraction(
            $state,
            (int) config('tackle-remote.answer_timeout', 600),
        ));

        $this->server = $this->startHttpServer($host, $port, $secret, $state);

        if ($this->server === null) {
            return self::FAILURE;
        }

        $this->components->info("Tackle Remote is up — session \"{$session}\"");
        $this->printPairing($guard, $host, $port);
        $this->line('  Pairing links are <options=bold>single-use</> — the first device to open one is paired, then it expires.');
        $this->line('  Press Ctrl+C to stop.');
        $this->newLine();

        $this->loop = new SessionLoop(
            $this->laravel->make(CodingAgent::class),
            $this->laravel->make(BudgetTracker::class),
            $this->laravel->make(SessionStore::class),
            $this->laravel->make(ConversationCompactor::class),
            $state,
            $session,
            (int) config('tackle-remote.poll_interval_ms', 400),
            // When a device claims the pairing code, print a fresh one so the
            // terminal always shows a working QR for the next device.
            onIdle: function () use ($guard, $host, $port) {
                if (! $guard->hasUnclaimedCode()) {
                    $this->components->info('Device paired. New pairing code for additional devices:');
                    $this->printPairing($guard, $host, $port);
                }
            },
        );

        $this->trapSignals();

        try {
            $this->loop->run();
        } finally {
            $this->server?->stop();
        }

        return self::SUCCESS;
    }

    /**
     * What this session is, for a client that may be holding several.
     *
     * Written here rather than served from the router because the router does
     * not boot Laravel and so cannot answer the only question that matters to
     * a person with five apps: which one is this?
     *
     * `api` is the contract version. A client shipped separately from the
     * server — an App Store binary against a Composer package that moves
     * several times a day — needs a way to know what it is talking to before
     * it assumes.
     *
     * @return array<string, mixed>
     */
    private function identity(string $session): array
    {
        $version = static function (string $package): ?string {
            try {
                return class_exists(InstalledVersions::class)
                    ? InstalledVersions::getPrettyVersion($package)
                    : null;
            } catch (Throwable) {
                return null;
            }
        };

        return [
            'api' => 1,
            'name' => (string) config('app.name', 'Laravel'),
            'environment' => (string) $this->laravel->environment(),
            'project' => basename(rtrim(base_path(), '/')),
            'session' => $session,
            'tackle' => $version('jordandalton/laravel-tackle'),
            'remote' => $version('jordandalton/laravel-tackle-remote'),
        ];
    }

    protected function startHttpServer(string $host, int $port, string $secret, RemoteState $state): ?Process
    {
        $socket = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );

        if ($socket === false) {
            $detail = $errorMessage !== '' ? $errorMessage : "error {$errorCode}";
            $this->components->error("Could not bind {$host}:{$port} — {$detail}");

            return null;
        }

        fclose($socket);

        $router = dirname(__DIR__, 2).'/server/router.php';

        $server = new Process(
            [PHP_BINARY, '-S', "{$host}:{$port}", $router],
            base_path(),
            [
                'TACKLE_REMOTE_DIR' => $state->dir(),
                'TACKLE_REMOTE_SECRET' => $secret,
                'TACKLE_REMOTE_LIFETIME' => (string) config('tackle-remote.session_lifetime', 43200),
                'TACKLE_REMOTE_SPA' => dirname(__DIR__, 2).'/resources/spa.html',
                'TACKLE_REMOTE_AUTOLOAD' => base_path('vendor/autoload.php'),
            ],
        );

        $server->setTimeout(null);
        // The built-in server logs every request to stderr. Symfony captures
        // child output in pipes by default, but the agent can stay inside a
        // long turn without returning here to drain them. Once a pipe fills,
        // PHP blocks while logging and every mobile/web request hangs. Send
        // both streams to the platform null device instead; the bind preflight
        // above keeps the useful startup failure actionable.
        $server->disableOutput();
        $server->start();

        // php -S fails fast when the port is taken; give it a beat to say so.
        usleep(300_000);

        if (! $server->isRunning()) {
            $exit = $server->getExitCode();
            $detail = $exit === null ? 'the server exited during startup' : "the server exited with code {$exit}";
            $this->components->error("Could not start the HTTP server on {$host}:{$port} — {$detail}.");

            return null;
        }

        return $server;
    }

    /**
     * Print the pairing URL, its QR code, and fallback URLs for machines
     * attached to several networks. All URLs carry the same single-use code —
     * whichever one the phone reaches first claims it.
     */
    private function printPairing(AccessGuard $guard, string $host, int $port): void
    {
        $code = $guard->issuePairingCode();
        $primary = $host === '0.0.0.0' ? ($this->lanAddress() ?? '127.0.0.1') : $host;
        $url = "http://{$primary}:{$port}/?pair={$code}";

        $this->line('  <options=bold>'.$url.'</>');
        $this->newLine();
        $this->line(TerminalQr::render($url));
        $this->newLine();

        $alternates = array_diff($this->candidateAddresses(), [$primary]);

        if ($host === '0.0.0.0' && $alternates !== []) {
            $this->line('  If the QR does not load, this machine is also reachable at:');

            foreach ($alternates as $address) {
                $this->line("    http://{$address}:{$port}/?pair={$code}");
            }

            $this->newLine();
        }
    }

    /**
     * The LAN IP the QR should point at. A connected UDP socket reveals which
     * local address the OS routes outbound traffic from (no packet is sent) —
     * far more reliable than scanning interfaces, which picks arbitrarily on
     * machines that sit on several networks (Wi-Fi + wired, VPN tunnels).
     */
    private function lanAddress(): ?string
    {
        if (function_exists('socket_create')) {
            $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

            if ($socket !== false
                && @socket_connect($socket, '8.8.8.8', 53)
                && @socket_getsockname($socket, $address)
                && $this->isPrivateAddress($address)) {
                return $address;
            }
        }

        return $this->candidateAddresses()[0] ?? null;
    }

    /**
     * Every private IPv4 address on this machine, for the fallback list.
     *
     * @return array<int, string>
     */
    private function candidateAddresses(): array
    {
        $addresses = [];

        foreach (@net_get_interfaces() ?: [] as $interface) {
            foreach ($interface['unicast'] ?? [] as $unicast) {
                $address = $unicast['address'] ?? '';

                if ($this->isPrivateAddress($address)) {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isPrivateAddress(string $address): bool
    {
        return str_starts_with($address, '192.168.')
            || str_starts_with($address, '10.')
            || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $address) === 1;
    }

    private function trapSignals(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, function () {
                $this->loop?->stop();
                $this->server?->stop();
            });
        }
    }
}
