<?php

namespace Ncpd\SecureShare\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;
use Ncpd\SecureShare\Actions\UnlockSecureShareAction;
use Ncpd\SecureShare\Enums\SecureShareStatus;
use Ncpd\SecureShare\Exceptions\ShareUnlockException;
use Ncpd\SecureShare\Models\SecureShare;
use Ncpd\SecureShare\Services\SecretCipher;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class SecureShareController
{
    public function show(string $share_token): View|Response
    {
        $share = $this->findViewableShare($share_token);

        if ($share === null) {
            return $this->unavailable();
        }

        return response()
            ->view('secure-share::public.unlock', $this->viewData(
                shareToken: $share_token,
                error: session('error'),
            ))
            ->header('Cache-Control', 'no-store');
    }

    public function unlock(Request $request, string $share_token): Response|RedirectResponse
    {
        $validated = $request->validate([
            'passcode' => ['required', 'string'],
        ]);

        try {
            $result = app(UnlockSecureShareAction::class)->execute($share_token, $validated['passcode']);
        } catch (ShareUnlockException) {
            return redirect()
                ->route('secure-share.show', ['share_token' => $share_token])
                ->with('error', __('secure-share::messages.unavailable'));
        }

        // Encrypt passcode material with APP_KEY before persisting TTL-bound
        // download authorization in the configured session backend.
        session()->put($this->sessionKey($share_token), [
            'at' => now()->getTimestamp(),
            'pc' => Crypt::encryptString($validated['passcode']),
        ]);

        return response()
            ->view('secure-share::public.unlock', $this->viewData(
                shareToken: $share_token,
                revealed: true,
                plaintext: $result['plaintext'],
                attachments: $result['share']->getMedia('secure-attachments'),
                share: $result['share'],
            ))
            ->header('Cache-Control', 'no-store');
    }

    public function download(string $share_token, string $media): StreamedResponse|RedirectResponse
    {
        $unlocked = session()->get($this->sessionKey($share_token));
        $ttl = (int) config('secure-share.unlock_ttl', 600);
        $now = now();
        $startedAt = is_array($unlocked) ? ($unlocked['at'] ?? null) : null;

        if (
            ! is_array($unlocked)
            || (! is_int($startedAt) && ! (is_string($startedAt) && ctype_digit($startedAt)))
            || ! isset($unlocked['pc'])
            || ! is_string($unlocked['pc'])
            || $unlocked['pc'] === ''
            || $now->getTimestamp() >= ((int) $startedAt + $ttl)
        ) {
            session()->forget($this->sessionKey($share_token));

            return redirect()
                ->route('secure-share.show', ['share_token' => $share_token])
                ->with('error', __('secure-share::messages.unavailable'));
        }

        /** @var class-string<SecureShare> $model */
        $model = config('secure-share.model');
        $share = $model::query()->where('share_token', $share_token)->first();

        if (! $share instanceof SecureShare) {
            session()->forget($this->sessionKey($share_token));

            return redirect()
                ->route('secure-share.show', ['share_token' => $share_token])
                ->with('error', __('secure-share::messages.unavailable'));
        }

        if ($share->expires_at !== null && $now->greaterThanOrEqualTo($share->expires_at)) {
            if ($share->status !== SecureShareStatus::Expired) {
                $share->status = SecureShareStatus::Expired;
                $share->save();
            }

            session()->forget($this->sessionKey($share_token));

            return redirect()
                ->route('secure-share.show', ['share_token' => $share_token])
                ->with('error', __('secure-share::messages.unavailable'));
        }

        if (! in_array($share->status, [
            SecureShareStatus::Active,
            SecureShareStatus::Burned,
            SecureShareStatus::Exhausted,
        ], true) || $share->download_until === null || $now->greaterThanOrEqualTo($share->download_until)) {
            session()->forget($this->sessionKey($share_token));

            return redirect()
                ->route('secure-share.show', ['share_token' => $share_token])
                ->with('error', __('secure-share::messages.unavailable'));
        }

        /** @var class-string<Media> $mediaModel */
        $mediaModel = $share->getMediaModel();
        $media = $mediaModel::query()->where('uuid', $media)->first();

        if (! $media instanceof Media) {
            abort(404);
        }

        $mediaProperties = is_array($media->custom_properties) ? $media->custom_properties : [];

        if (! empty($mediaProperties['removed_at'])) {
            abort(404);
        }

        // Cross-share isolation: the media record must belong to THIS share
        // and THIS collection, or the URL is a probing attempt.
        if (
            $media->model_type !== $share->getMorphClass()
            || (int) $media->model_id !== $share->id
            || $media->collection_name !== 'secure-attachments'
        ) {
            abort(404);
        }

        $originalName = $media->custom_properties['original_name'] ?? 'attachment';
        $originalMime = $media->custom_properties['original_mime'] ?? 'application/octet-stream';

        try {
            $passcode = Crypt::decryptString($unlocked['pc']);
        } catch (Throwable) {
            session()->forget($this->sessionKey($share_token));

            return redirect()
                ->route('secure-share.show', ['share_token' => $share_token])
                ->with('error', __('secure-share::messages.unavailable'));
        }

        return response()->streamDownload(
            function () use ($media, $passcode): void {
                // Decrypt straight from the media stream into the response —
                // plaintext never touches disk.
                echo SecretCipher::decrypt(stream_get_contents($media->stream()), $passcode);
            },
            $originalName,
            [
                'Content-Type' => $originalMime,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ],
        );
    }

    private function findViewableShare(string $shareToken): ?SecureShare
    {
        /** @var class-string<SecureShare> $model */
        $model = config('secure-share.model');

        $share = $model::query()->where('share_token', $shareToken)->first();

        if (! $share instanceof SecureShare || $share->status !== SecureShareStatus::Active) {
            return null;
        }

        if ($share->expires_at !== null && now()->greaterThanOrEqualTo($share->expires_at)) {
            $share->status = SecureShareStatus::Expired;
            $share->save();

            return null;
        }

        return $share;
    }

    /**
     * @param  Collection<int, Media>|array<int, Media>  $attachments
     */
    private function viewData(
        string $shareToken,
        ?string $error = null,
        bool $revealed = false,
        ?string $plaintext = null,
        Collection|array $attachments = [],
        ?SecureShare $share = null,
    ): array {
        return [
            'shareToken' => $shareToken,
            'error' => $error,
            'revealed' => $revealed,
            'plaintext' => $plaintext,
            'attachments' => collect($attachments),
            'share' => $share,
        ];
    }

    private function unavailable(): Response
    {
        return response()
            ->view('secure-share::public.unlock', $this->viewData(
                shareToken: '',
                error: __('secure-share::messages.unavailable'),
            ), 404)
            ->header('Cache-Control', 'no-store');
    }

    private function sessionKey(string $shareToken): string
    {
        return 'secure-share.unlocked.'.$shareToken;
    }
}
