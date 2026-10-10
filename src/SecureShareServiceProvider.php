<?php

namespace Jodeveloper\SecureShare;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schedule;
use Jodeveloper\SecureShare\Console\Commands\PurgeExpiredSharesCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class SecureShareServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('secure-share')
            ->hasConfigFile()
            ->hasMigrations([
                'create_secure_shares_table',
                'add_download_until_to_secure_shares_table',
            ])
            ->hasRoute('web')
            ->hasViews()
            ->hasTranslations()
            ->hasCommand(PurgeExpiredSharesCommand::class);
    }

    public function packageBooted(): void
    {
        // Config-driven throttle ('requests,minutes') keyed by share token
        // AND client IP, so brute-forcing one link doesn't burn the budget
        // of every other link, and vice versa.
        RateLimiter::for('secure-share', function (Request $request) {
            [$requests, $minutes] = array_pad(
                explode(',', (string) config('secure-share.throttle', '6,1')),
                2,
                null
            );

            $requests = max(1, (int) $requests);
            $minutes = max(1, (int) ($minutes ?? 1));

            return [
                Limit::perMinutes($minutes, $requests)->by(
                    ($request->route('share_token') ?? 'global').'|'.$request->ip()
                ),
            ];
        });

        if (config('secure-share.purge.schedule', true)) {
            Schedule::command('secure-share:purge')->hourly();
        }
    }
}
