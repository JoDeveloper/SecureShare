<?php

namespace Ncpd\SecureShare\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Kirschbaum\PowerJoins\PowerJoinsServiceProvider;
use Livewire\LivewireServiceProvider;
use Ncpd\SecureShare\SecureShareServiceProvider;
use Ncpd\SecureShare\Tests\Fixtures\AdminPanelProvider;
use Ncpd\SecureShare\Tests\Fixtures\TestUser;
use Orchestra\Testbench\TestCase as BaseTestCase;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Termwind\Laravel\TermwindServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            // Full Filament runtime stack, in dependency order — the same set
            // a host app gets automatically from composer package discovery.
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            LivewireServiceProvider::class,
            TermwindServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            PowerJoinsServiceProvider::class,
            SupportServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            ActionsServiceProvider::class,
            NotificationsServiceProvider::class,
            InfolistsServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            FilamentServiceProvider::class,

            // Under test
            SecureShareServiceProvider::class,
            MediaLibraryServiceProvider::class,
            AdminPanelProvider::class,
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
        foreach ([
            '*create_secure_shares_table*.php',
            '*add_download_until_to_secure_shares_table*.php',
        ] as $pattern) {
            foreach (glob($this->app->databasePath('migrations/'.$pattern)) ?: [] as $stale) {
                unlink($stale);
            }
        }
        $this->artisan('vendor:publish', ['--tag' => 'secure-share-migrations']);

        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }
}
