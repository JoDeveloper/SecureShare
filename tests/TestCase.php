<?php

namespace Ncpd\SecureShare\Tests;

use Ncpd\SecureShare\SecureShareServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            SecureShareServiceProvider::class,
            MediaLibraryServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('filesystems.disks.test', [
            'driver' => 'local',
            'root' => storage_path('app-test-media'),
        ]);
        $app['config']->set('media-library.disk_name', 'test');
    }

    protected function defineDatabaseMigrations()
    {
        // users table (Testbench skeleton migrations)
        $this->loadLaravelMigrations(['--database' => 'testing']);

        // media table + this package's secure_shares table, via publish tags
        $this->artisan('vendor:publish', [
            '--provider' => MediaLibraryServiceProvider::class,
            '--tag' => 'medialibrary-migrations',
        ]);

        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }
}
