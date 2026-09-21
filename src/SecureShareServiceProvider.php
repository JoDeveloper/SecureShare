<?php

namespace Ncpd\SecureShare;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class SecureShareServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('secure-share')
            ->hasConfigFile()
            ->hasMigration('create_secure_shares_table')
            ->hasRoute('web')
            ->hasViews()
            ->hasTranslations();
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
    }
}
