<?php

namespace Ncpd\SecureShare\Tests;

use Ncpd\SecureShare\SecureShareServiceProvider;
use Ncpd\SecureShare\Tests\Fixtures\TestUser;
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
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('filesystems.disks.test', [
            'driver' => 'local',
            'root' => storage_path('app-test-media'),
        ]);
        $app['config']->set('media-library.disk_name', 'test');
        $app['config']->set('auth.providers.users.model', TestUser::class);
        $app['config']->set('auth.guard', 'web');
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

        // Remove stale published copies so every run tests the current stub.
        foreach (glob($this->app->databasePath('migrations/*create_secure_shares_table*.php')) ?: [] as $stale) {
            unlink($stale);
        }
        $this->artisan('vendor:publish', ['--tag' => 'secure-share-migrations']);

        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }
}
