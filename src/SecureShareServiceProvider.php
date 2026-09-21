<?php

namespace Ncpd\SecureShare;

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
}
