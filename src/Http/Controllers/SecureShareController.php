<?php

namespace Ncpd\SecureShare\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Ncpd\SecureShare\Actions\UnlockSecureShareAction;
use Ncpd\SecureShare\Enums\SecureShareStatus;
use Ncpd\SecureShare\Exceptions\ShareUnlockException;
use Ncpd\SecureShare\Models\SecureShare;
use Ncpd\SecureShare\Services\SecretCipher;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        // Transient, TTL-bound unlock state. The passcode lives ONLY here —
        // server-side session, never DB/logs/cache — for exactly as long as
        // the download route needs it to stream decrypted bytes. See the
        // package README's security model for why this trade-off exists.
        session()->put($this->sessionKey($share_token), [
            'at' => now()->getTimestamp(),
            'pc' => $validated['passcode'],
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

    public function download(string $share_token, Media $media): StreamedResponse|RedirectResponse
    {
        $unlocked = session()->get($this->sessionKey($share_token));
        $ttl = (int) config('secure-share.unlock_ttl', 600);

        if (
            ! is_array($unlocked)
            || ! isset($unlocked['at'], $unlocked['pc'])
            || (time() - (int) $unlocked['at']) > $ttl
        ) {
            session()->forget($this->sessionKey($share_token));

            return redirect()
                ->route('secure-share.show', ['share_token' => $share_token])
                ->with('error', __('secure-share::messages.unavailable'));
        }

        /** @var class-string<SecureShare> $model */
        $model = config('secure-share.model');
        $share = $model::query()->where('share_token', $share_token)->first();

        // Cross-share isolation: the media record must belong to THIS share
        // and THIS collection, or the URL is a probing attempt.
        if (
            ! $share instanceof SecureShare
            || $media->model_type !== $share->getMorphClass()
            || (int) $media->model_id !== $share->id
            || $media->collection_name !== 'secure-attachments'
        ) {
            abort(404);
        }

        $originalName = $media->custom_properties['original_name'] ?? 'attachment';
        $originalMime = $media->custom_properties['original_mime'] ?? 'application/octet-stream';
        $passcode = (string) $unlocked['pc'];

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

        if ($share->expires_at !== null && $share->expires_at->isPast()) {
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
