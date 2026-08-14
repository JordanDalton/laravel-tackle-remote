<?php

namespace TackleRemote\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tackle\TackleServiceProvider;
use TackleRemote\TackleRemoteServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            TackleServiceProvider::class,
            TackleRemoteServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('tackle-remote.storage_path', sys_get_temp_dir().'/tackle-remote-tests/'.uniqid());
        config()->set('tackle-remote.answer_timeout', 1);
    }
}
