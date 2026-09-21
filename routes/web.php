<?php

use Illuminate\Support\Facades\Route;
use Ncpd\SecureShare\Http\Controllers\SecureShareController;

Route::prefix((string) config('secure-share.route.prefix', 'secure-share'))
    ->middleware((array) config('secure-share.route.middleware', ['web']))
    ->name('secure-share.')
    ->group(function (): void {
        Route::get('/{share_token}', [SecureShareController::class, 'show'])
            ->name('show');

        Route::post('/{share_token}', [SecureShareController::class, 'unlock'])
            ->middleware('throttle:secure-share')
            ->name('unlock');

        Route::get('/{share_token}/download/{media:uuid}', [SecureShareController::class, 'download'])
            ->middleware('throttle:secure-share')
            ->name('download');
    });
