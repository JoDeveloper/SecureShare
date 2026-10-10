<?php

namespace Ncpd\SecureShare\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Ncpd\SecureShare\Actions\CreateSecureShareAction;
use Ncpd\SecureShare\Filament\Schemas\SecureShareForm;

final class SecureShareAction extends Action
{
    /**
     * @var array<int, UploadedFile|array{disk: string, path: string, name: string, mime?: string}>|Closure|null
     */
    protected array|Closure|null $attachmentsResolver = null;

    public static function getDefaultName(): ?string
    {
        return 'secureShare';
    }

    /**
     * @param  array<int, UploadedFile|array{disk: string, path: string, name: string, mime?: string}>|Closure  $attachments
     */
    public function attachments(array|Closure $attachments): static
    {
        $this->attachmentsResolver = $attachments;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('secure-share::messages.create'))
            ->icon('heroicon-o-lock-closed')
            ->modalHeading(__('secure-share::messages.create_title'))
            ->modalSubmitActionLabel(__('secure-share::messages.create'))
            ->databaseTransaction(false)
            ->authorize(fn (): bool => Filament::auth()->check())
            ->authorizationNotification()
            ->authorizationMessage(__('secure-share::messages.unauthorized'))
            ->successNotification(null)
            ->schema(fn (): array => SecureShareForm::components($this->attachmentsResolver === null))
            ->action(function (array $data): void {
                $guard = Filament::auth();

                if (! $guard->check()) {
                    throw new AuthorizationException(__('secure-share::messages.unauthorized'));
                }

                $attachments = $this->resolveAttachments($data);
                $maxViews = $data['max_views'] ?? null;

                $share = app(CreateSecureShareAction::class)->execute(
                    passcode: $data['passcode'],
                    text: $data['text'] ?? null,
                    attachments: $attachments,
                    options: [
                        'created_by' => Filament::auth()->id(),
                        'expires_at' => SecureShareForm::resolveExpiry((string) ($data['expires_in'] ?? '24h')),
                        'max_views' => filled($maxViews) ? $maxViews : null,
                        'burn_after_reading' => (bool) ($data['burn_after_reading'] ?? false),
                    ],
                );

                $url = route('secure-share.show', ['share_token' => $share->share_token]);

                Notification::make()
                    ->title(__('secure-share::messages.created'))
                    ->body($url)
                    ->success()
                    ->persistent()
                    ->actions([
                        Action::make('open')
                            ->label(__('secure-share::messages.open'))
                            ->url($url)
                            ->openUrlInNewTab(),
                    ])
                    ->send();

                $this->success();
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, UploadedFile|array{disk: string, path: string, name: string, mime?: string}>
     */
    protected function resolveAttachments(array $data): array
    {
        $attachments = $this->attachmentsResolver === null
            ? ($data['attachments'] ?? [])
            : $this->evaluate($this->attachmentsResolver, ['data' => $data]);

        if (is_array($attachments) && array_key_exists('path', $attachments)) {
            return [$attachments];
        }

        return array_values(Arr::wrap($attachments));
    }
}
