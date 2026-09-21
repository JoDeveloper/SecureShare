<?php

namespace Ncpd\SecureShare\Actions;

use Ncpd\SecureShare\Enums\SecureShareStatus;
use Ncpd\SecureShare\Exceptions\DecryptionFailedException;
use Ncpd\SecureShare\Exceptions\ShareUnlockException;
use Ncpd\SecureShare\Jobs\DeleteSecureShareJob;
use Ncpd\SecureShare\Models\SecureShare;
use Ncpd\SecureShare\Services\SecretCipher;

final class UnlockSecureShareAction
{
    /**
     * Verify a passcode against a share and consume one view.
     *
     * @return array{share: SecureShare, plaintext: ?string}
     *
     * @throws ShareUnlockException on every failure mode, always with the
     *         identical generic message.
     */
    public function execute(string $shareToken, string $passcode): array
    {
        /** @var class-string<SecureShare> $model */
        $model = config('secure-share.model');

        $share = $model::query()->where('share_token', $shareToken)->first();

        if (! $share instanceof SecureShare || $share->status !== SecureShareStatus::Active) {
            throw ShareUnlockException::generic();
        }

        if ($share->locked_until !== null && $share->locked_until->isFuture()) {
            throw ShareUnlockException::generic();
        }

        if ($share->expires_at !== null && $share->expires_at->isPast()) {
            $share->status = SecureShareStatus::Expired;
            $share->save();

            throw ShareUnlockException::generic();
        }

        try {
            $plaintext = $share->ciphertext !== null
                ? SecretCipher::decrypt($share->ciphertext, $passcode)
                : null;
        } catch (DecryptionFailedException) {
            $this->registerFailedAttempt($share);

            throw ShareUnlockException::generic();
        }

        $share->failed_attempts = 0;
        $share->view_count += 1;

        if ($share->burn_after_reading) {
            $share->status = SecureShareStatus::Burned;
            $share->save();

            // Queued, so the reveal response is sent before deletion starts.
            DeleteSecureShareJob::dispatch($share->share_token);
        } else {
            if ($share->max_views !== null && $share->view_count >= $share->max_views) {
                $share->status = SecureShareStatus::Exhausted;
            }

            $share->save();
        }

        return ['share' => $share, 'plaintext' => $plaintext];
    }

    private function registerFailedAttempt(SecureShare $share): void
    {
        $maxAttempts = (int) config('secure-share.lockout.max_attempts', 5);
        $lockoutMinutes = (int) config('secure-share.lockout.lockout_minutes', 15);

        $share->failed_attempts += 1;

        if ($share->failed_attempts >= $maxAttempts) {
            // Fresh attempt budget once the lock expires.
            $share->failed_attempts = 0;
            $share->locked_until = now()->addMinutes($lockoutMinutes);
        }

        $share->save();
    }
}
