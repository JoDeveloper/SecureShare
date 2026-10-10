<?php

namespace Ncpd\SecureShare\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Ncpd\SecureShare\Actions\CreateSecureShareAction;
use Ncpd\SecureShare\Filament\Schemas\SecureShareForm;

final class CreateSecureShare extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';

    protected string $view = 'secure-share::filament.pages.create-secure-share';

    public ?array $data = [];

    public ?string $createdUrl = null;

    public ?string $createdToken = null;

    public bool $createdBurn = false;

    public static function getNavigationLabel(): string
    {
        return self::$navigationLabel ?? __('secure-share::messages.create_nav');
    }

    public function getTitle(): string
    {
        return self::$title ?? __('secure-share::messages.create_title');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(SecureShareForm::components())->statePath('data');
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
                'created_by' => Filament::auth()->id(),
                'expires_at' => SecureShareForm::resolveExpiry($state['expires_in'] ?? '24h'),
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
}
