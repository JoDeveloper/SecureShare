<?php

namespace Ncpd\SecureShare\Filament\Schemas;

use Carbon\CarbonInterface;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Validation\ValidationException;

final class SecureShareForm
{
    /**
     * @return array<int, object>
     */
    public static function components(bool $withContent = true): array
    {
        $components = [
            TextInput::make('passcode')
                ->label(__('secure-share::messages.passcode'))
                ->password()
                ->revealable()
                ->required()
                ->minLength((int) config('secure-share.passcode.min_length', 8))
                ->maxLength(1024)
                ->same('passcode_confirmation')
                ->validationAttribute(__('secure-share::messages.passcode')),

            TextInput::make('passcode_confirmation')
                ->label(__('secure-share::messages.confirm_passcode'))
                ->password()
                ->revealable()
                ->required(),
        ];

        if ($withContent) {
            $components[] = Textarea::make('text')
                ->label(__('secure-share::messages.secret'))
                ->nullable()
                ->maxLength(65535)
                ->rows(6)
                ->columnSpanFull();

            $components[] = FileUpload::make('attachments')
                ->label(__('secure-share::messages.attachments'))
                ->hint(__('secure-share::messages.attachments_hint'))
                ->multiple()
                ->storeFiles(false)
                ->maxSize((int) config('secure-share.max_upload_kb', 10240))
                ->columnSpanFull();
        }

        return [
            ...$components,

            Select::make('expires_in')
                ->label(__('secure-share::messages.expires_at'))
                ->options([
                    '30m' => __('secure-share::messages.expires_30m'),
                    '1h' => __('secure-share::messages.expires_1h'),
                    '24h' => __('secure-share::messages.expires_24h'),
                    '7d' => __('secure-share::messages.expires_7d'),
                    '30d' => __('secure-share::messages.expires_30d'),
                    'never' => __('secure-share::messages.expires_never'),
                ])
                ->default('24h')
                ->required()
                ->selectablePlaceholder(false),

            TextInput::make('max_views')
                ->label(__('secure-share::messages.max_views'))
                ->integer()
                ->minValue(1)
                ->nullable(),

            Toggle::make('burn_after_reading')
                ->label(__('secure-share::messages.burn_after_reading'))
                ->hint(__('secure-share::messages.burn_hint'))
                ->default(false)
                ->columnSpanFull(),
        ];
    }

    public static function resolveExpiry(string $expiresIn): ?CarbonInterface
    {
        return match ($expiresIn) {
            '30m' => now()->addMinutes(30),
            '1h' => now()->addHour(),
            '24h' => now()->addDay(),
            '7d' => now()->addDays(7),
            '30d' => now()->addDays(30),
            'never' => null,
            default => throw ValidationException::withMessages([
                'expires_in' => __('secure-share::messages.invalid_expiry'),
            ]),
        };
    }
}
