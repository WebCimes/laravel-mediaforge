<?php

namespace Webcimes\LaravelMediaforge\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Webcimes\LaravelMediaforge\MediaForgeServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [MediaForgeServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path('app/public'),
        ]);

        $app['config']->set('mediaforge.driver', 'gd');
    }
}
