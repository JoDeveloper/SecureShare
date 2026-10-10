<?php

namespace Jodeveloper\SecureShare\Actions;

use Jodeveloper\SecureShare\Enums\SecureShareStatus;
use Jodeveloper\SecureShare\Exceptions\DecryptionFailedException;
use Jodeveloper\SecureShare\Exceptions\ShareUnlockException;
use Jodeveloper\SecureShare\Jobs\DeleteSecureShareJob;
use Jodeveloper\SecureShare\Models\SecureShare;
use Jodeveloper\SecureShare\Services\SecretCipher;

final class UnlockSecureShareAction
{
    /**
     * Verify a passcode against a share and consume one view.
     *
     * @return array{share: SecureShare, plaintext: ?string}
     *
     * @throws ShareUnlockException on every failure mode, always with the
     *                              identical generic message.
     */
    public function execute(string $shareToken, string $passcode): array
    {
        /** @var class-string<SecureShare> $model */
        $model = config('secure-share.model');

        $connection = (new $model)->getConnection();

        $result = $connection->transaction(function () use ($model, $shareToken, $passcode): array {
            if (mb_strlen($passcode, 'UTF-8') > 1024) {
                return ['failed' => true];
            }

            /** @var SecureShare|null $share */
            $share = $model::query()
                ->where('share_token', $shareToken)
                ->lockForUpdate()
                ->first();

            if (! $share instanceof SecureShare || $share->status !== SecureShareStatus::Active) {
                return ['failed' => true];
            }

            $now = now();

            if ($share->locked_until !== null && $share->locked_until->isFuture()) {
                return ['failed' => true];
            }

            if ($share->expires_at !== null && $now->greaterThanOrEqualTo($share->expires_at)) {
                $share->status = SecureShareStatus::Expired;
                $share->save();

                return ['failed' => true];
            }

            if ($share->max_views !== null && $share->view_count >= $share->max_views) {
                $share->status = SecureShareStatus::Exhausted;
                $share->save();

                return ['failed' => true];
            }

            if ($share->ciphertext === null) {
                return ['failed' => true];
            }

            try {
                $plaintext = SecretCipher::decrypt($share->ciphertext, $passcode);
            } catch (DecryptionFailedException) {
                $this->registerFailedAttempt($share);

                return ['failed' => true];
            }

            $share->failed_attempts = 0;
            $share->view_count = (int) $share->view_count + 1;

            if ($share->has_attachments) {
                $share->download_until = $now->copy()->addSeconds(
                    (int) config('secure-share.unlock_ttl', 600)
                );
            }

            $deleteImmediately = false;

            if ($share->burn_after_reading) {
                $share->status = SecureShareStatus::Burned;
                $deleteImmediately = ! $share->has_attachments;
            } elseif ($share->max_views !== null && $share->view_count >= $share->max_views) {
                $share->status = SecureShareStatus::Exhausted;
            }

            $share->save();

            return [
                'failed' => false,
                'share' => $share,
                'plaintext' => $plaintext,
                'delete_immediately' => $deleteImmediately,
            ];
        });

        if ($result['failed']) {
            throw ShareUnlockException::generic();
        }

        if ($result['delete_immediately']) {
            DeleteSecureShareJob::dispatch($result['share']->share_token);
        }

        return [
            'share' => $result['share'],
            'plaintext' => $result['plaintext'],
        ];
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
