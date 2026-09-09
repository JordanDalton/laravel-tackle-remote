<?php

namespace TackleRemote;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use TackleRemote\Commands\ConnectCommand;
use TackleRemote\Commands\RemoteCommand;
use TackleRemote\Commands\RestartConnectCommand;

class TackleRemoteServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-tackle-remote')
            ->hasConfigFile('tackle-remote')
            ->hasCommands([
                ConnectCommand::class,
                RemoteCommand::class,
                RestartConnectCommand::class,
            ]);
    }
}
