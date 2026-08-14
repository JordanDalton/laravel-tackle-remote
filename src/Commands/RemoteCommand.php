<?php

namespace TackleRemote\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Tackle\Contracts\CodingAgent;
use Tackle\Contracts\InteractionPolicy;
use Tackle\Support\BudgetTracker;
use Tackle\Support\ConversationCompactor;
use Tackle\Support\SessionStore;
use TackleRemote\Support\RemoteInteraction;
use TackleRemote\Support\RemoteState;
use TackleRemote\Support\SessionLoop;
use TackleRemote\Support\TerminalQr;

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

        $token = bin2hex(random_bytes(16));

        // The browser answers questions from here on — bind before the agent
        // (and its tools) resolve, so every ConfirmAction/AskUser goes to the UI.
        $this->laravel->instance(InteractionPolicy::class, new RemoteInteraction(
            $state,
            (int) config('tackle-remote.answer_timeout', 600),
        ));

        $this->server = $this->startHttpServer($host, $port, $token, $state);

        if ($this->server === null) {
            return self::FAILURE;
        }

        $url = $this->publicUrl($host, $port, $token);

        $this->components->info("Tackle Remote is up — session \"{$session}\"");
        $this->line('  <options=bold>'.$url.'</>');
        $this->newLine();
        $this->line(TerminalQr::render($url));
        $this->newLine();
        $this->line('  Scan with your phone. <fg=yellow>Anyone with this link can drive the agent</> — it dies with this process.');
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
        );

        $this->trapSignals();

        try {
            $this->loop->run();
        } finally {
            $this->server?->stop();
        }

        return self::SUCCESS;
    }

    private function startHttpServer(string $host, int $port, string $token, RemoteState $state): ?Process
    {
        $router = dirname(__DIR__, 2).'/server/router.php';

        $server = new Process(
            [PHP_BINARY, '-S', "{$host}:{$port}", $router],
            base_path(),
            [
                'TACKLE_REMOTE_DIR' => $state->dir(),
                'TACKLE_REMOTE_TOKEN' => $token,
                'TACKLE_REMOTE_SPA' => dirname(__DIR__, 2).'/resources/spa.html',
                'TACKLE_REMOTE_AUTOLOAD' => base_path('vendor/autoload.php'),
            ],
        );

        $server->setTimeout(null);
        $server->start();

        // php -S fails fast when the port is taken; give it a beat to say so.
        usleep(300_000);

        if (! $server->isRunning()) {
            $this->components->error("Could not bind {$host}:{$port} — ".trim($server->getErrorOutput()));

            return null;
        }

        return $server;
    }

    private function publicUrl(string $host, int $port, string $token): string
    {
        $display = $host === '0.0.0.0' ? ($this->lanAddress() ?? '127.0.0.1') : $host;

        return "http://{$display}:{$port}/?t={$token}";
    }

    /**
     * Best-effort LAN IP so the printed URL / QR works from a phone when
     * binding to all interfaces.
     */
    private function lanAddress(): ?string
    {
        $interfaces = @net_get_interfaces() ?: [];

        foreach ($interfaces as $interface) {
            foreach ($interface['unicast'] ?? [] as $unicast) {
                $address = $unicast['address'] ?? '';

                if (str_starts_with($address, '192.168.') || str_starts_with($address, '10.')) {
                    return $address;
                }
            }
        }

        return null;
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
