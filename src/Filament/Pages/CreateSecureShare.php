<?php

namespace Ncpd\SecureShare\Filament\Pages;

use Carbon\CarbonInterface;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Ncpd\SecureShare\Actions\CreateSecureShareAction;
use Ncpd\SecureShare\Models\SecureShare;

final class CreateSecureShare extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-lock-closed';

    protected string $view = 'secure-share::filament.pages.create-secure-share';

    public ?array $data = [];

    public ?string $createdUrl = null;

    public ?string $createdToken = null;

    public bool $createdBurn = false;

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ?? __('secure-share::messages.create_nav');
    }

    public function getTitle(): string
    {
        return static::$title ?? __('secure-share::messages.create_title');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('passcode')
                    ->label(__('secure-share::messages.passcode'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength((int) config('secure-share.passcode.min_length', 8))
                    ->same('passcode_confirmation')
                    ->validationAttribute(__('secure-share::messages.passcode')),

                TextInput::make('passcode_confirmation')
                    ->label(__('secure-share::messages.confirm_passcode'))
                    ->password()
                    ->revealable()
                    ->required(),

                Textarea::make('text')
                    ->label(__('secure-share::messages.secret'))
                    ->nullable()
                    ->maxLength(65535)
                    ->rows(6)
                    ->columnSpanFull(),

                FileUpload::make('attachments')
                    ->label(__('secure-share::messages.attachments'))
                    ->hint(__('secure-share::messages.attachments_hint'))
                    ->multiple()
                    ->storeFiles(false)
                    ->maxSize((int) config('secure-share.max_upload_kb', 10240))
                    ->columnSpanFull(),

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
                    ->selectablePlaceholder(false),

                TextInput::make('max_views')
                    ->label(__('secure-share::messages.max_views'))
                    ->numeric()
                    ->minValue(1)
                    ->nullable(),

                Toggle::make('burn_after_reading')
                    ->label(__('secure-share::messages.burn_after_reading'))
                    ->hint(__('secure-share::messages.burn_hint'))
                    ->default(false)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function create(): void
    {
        $state = $this->form->getState();

        $attachments = array_values(Arr::wrap($state['attachments'] ?? []));

        if (blank($state['text'] ?? null) && count($attachments) === 0) {
            Notification::make()
                ->title(__('secure-share::messages.need_content'))
                ->danger()
                ->send();

            return;
        }

        $share = app(CreateSecureShareAction::class)->execute(
            passcode: $state['passcode'],
            text: $state['text'] ?? null,
            attachments: $attachments,
            options: [
                'created_by' => auth()->id(),
                'expires_at' => $this->resolveExpiry($state['expires_in'] ?? '24h'),
                'max_views' => $state['max_views'] ?? null,
                'burn_after_reading' => (bool) ($state['burn_after_reading'] ?? false),
            ],
        );

        $this->createdToken = $share->share_token;
        $this->createdUrl = route('secure-share.show', ['share_token' => $share->share_token]);
        $this->createdBurn = $share->burn_after_reading;

        // Never leave the passcode sitting in component state.
        $this->form->fill();

        Notification::make()
            ->title(__('secure-share::messages.created'))
            ->success()
            ->send();
    }

    private function resolveExpiry(string $expiresIn): ?CarbonInterface
    {
        return match ($expiresIn) {
            '30m' => now()->addMinutes(30),
            '1h' => now()->addHour(),
            '24h' => now()->addDay(),
            '7d' => now()->addDays(7),
            '30d' => now()->addDays(30),
            default => null,
        };
    }
}
