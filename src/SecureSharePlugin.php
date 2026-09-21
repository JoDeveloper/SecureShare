<?php

namespace Ncpd\SecureShare;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Ncpd\SecureShare\Filament\Pages\CreateSecureShare;

final class SecureSharePlugin implements Plugin
{
    public function getId(): string
    {
        return 'secure-share';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([CreateSecureShare::class]);
    }

    public function boot(Panel $panel): void
    {
    }

    public static function make(): static
    {
        return app(static::class);
    }
}
