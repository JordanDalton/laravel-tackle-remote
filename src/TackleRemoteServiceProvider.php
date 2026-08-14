<?php

namespace TackleRemote;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use TackleRemote\Commands\RemoteCommand;

class TackleRemoteServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-tackle-remote')
            ->hasConfigFile('tackle-remote')
            ->hasCommands([
                RemoteCommand::class,
            ]);
    }
}
