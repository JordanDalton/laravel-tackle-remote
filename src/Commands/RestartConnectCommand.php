<?php

namespace TackleRemote\Commands;

use Illuminate\Console\Command;
use TackleRemote\Support\ConnectorRestartSignal;

class RestartConnectCommand extends Command
{
    protected $signature = 'tackle:connect:restart';

    protected $description = 'Gracefully restart Tackle Cloud connector daemons';

    public function handle(): int
    {
        (new ConnectorRestartSignal(
            (string) config('tackle-remote.connector_restart_signal_path'),
        ))->request();

        $this->components->info('Tackle Cloud connector restart requested.');

        return self::SUCCESS;
    }
}
