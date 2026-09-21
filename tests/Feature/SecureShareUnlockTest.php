<?php

namespace Ncpd\SecureShare\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Ncpd\SecureShare\Actions\CreateSecureShareAction;
use Ncpd\SecureShare\Actions\UnlockSecureShareAction;
use Ncpd\SecureShare\Enums\SecureShareStatus;
use Ncpd\SecureShare\Exceptions\ShareUnlockException;
use Ncpd\SecureShare\Models\SecureShare;
use Ncpd\SecureShare\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SecureShareUnlockTest extends TestCase
{
    private const PASSCODE = 'correct-horse-battery';

    private function createShare(array $options = [], string $text = 'the secret body'): SecureShare
    {
        return app(CreateSecureShareAction::class)->execute(
            passcode: self::PASSCODE,
            text: $text,
            options: $options,
        );
    }

    #[Test]
    public function unlock_returns_plaintext_and_increments_view_count(): void
    {
        $share = $this->createShare();

        $result = app(UnlockSecureShareAction::class)->execute($share->share_token, self::PASSCODE);

        $this->assertSame($share->id, $result['share']->id);
        $this->assertSame('the secret body', $result['plaintext']);
        $this->assertSame(1, SecureShare::find($share->id)->view_count);
        $this->assertSame(0, SecureShare::find($share->id)->failed_attempts);
    }

    #[Test]
    public function attachment_only_share_still_requires_correct_passcode(): void
    {
        $file = UploadedFile::fake()->createWithContent('only.bin', 'BYTES');

        $share = app(CreateSecureShareAction::class)->execute(
            passcode: self::PASSCODE,
            attachments: [$file],
        );

        $this->assertNotNull($share->ciphertext, 'A verification ciphertext must always be stored');

        $this->expectException(ShareUnlockException::class);
        app(UnlockSecureShareAction::class)->execute($share->share_token, 'wrong-passcode-123');
    }

    #[Test]
    public function wrong_passcode_increments_failed_attempts(): void
    {
        $share = $this->createShare();

        try {
            app(UnlockSecureShareAction::class)->execute($share->share_token, 'wrong-passcode-123');
            $this->fail('ShareUnlockException was not thrown');
        } catch (ShareUnlockException) {
        }

        $this->assertSame(1, $share->refresh()->failed_attempts);
        $this->assertSame(0, $share->view_count, 'Failed attempts must not count as views');
        $this->assertNull($share->locked_until);
    }

    #[Test]
    public function lockout_engages_after_configured_attempts_and_rejects_correct_passcode(): void
    {
        $share = $this->createShare();
        $action = app(UnlockSecureShareAction::class);

        for ($i = 0; $i < 5; $i++) {
            try {
                $action->execute($share->share_token, 'wrong-passcode-123');
            } catch (ShareUnlockException) {
            }
        }

        $share->refresh();
        $this->assertNotNull($share->locked_until, 'Lockout must engage after max_attempts failures');
        $this->assertTrue($share->locked_until->isFuture());
        $this->assertSame(0, $share->failed_attempts, 'Counter resets when the lock engages');

        // Even the CORRECT passcode is rejected while locked.
        $this->expectException(ShareUnlockException::class);
        $action->execute($share->share_token, self::PASSCODE);
    }

    #[Test]
    public function lockout_expires_and_allows_retry(): void
    {
        $share = $this->createShare();
        $action = app(UnlockSecureShareAction::class);

        for ($i = 0; $i < 5; $i++) {
            try {
                $action->execute($share->share_token, 'wrong-passcode-123');
            } catch (ShareUnlockException) {
            }
        }

        \Carbon\Carbon::setTestNow(now()->addMinutes(16));

        $result = $action->execute($share->share_token, self::PASSCODE);
        $this->assertSame('the secret body', $result['plaintext']);

        \Carbon\Carbon::setTestNow();
    }

    #[Test]
    public function expired_share_flips_status_and_is_rejected(): void
    {
        $share = $this->createShare(['expires_at' => now()->subMinute()]);

        try {
            app(UnlockSecureShareAction::class)->execute($share->share_token, self::PASSCODE);
            $this->fail('ShareUnlockException was not thrown');
        } catch (ShareUnlockException) {
            // expected
        }

        $this->assertSame(SecureShareStatus::Expired, $share->refresh()->status);
    }

    #[Test]
    public function share_is_exhausted_after_final_view(): void
    {
        $share = $this->createShare(['max_views' => 2]);
        $action = app(UnlockSecureShareAction::class);

        $action->execute($share->share_token, self::PASSCODE);
        $this->assertSame(SecureShareStatus::Active, $share->refresh()->status);

        // Second (final) view still succeeds…
        $action->execute($share->share_token, self::PASSCODE);
        $this->assertSame(SecureShareStatus::Exhausted, $share->refresh()->status);

        // …but a third is rejected.
        $this->expectException(ShareUnlockException::class);
        $action->execute($share->share_token, self::PASSCODE);
    }

    #[Test]
    public function burn_after_reading_deletes_share_and_media_files(): void
    {
        $file = UploadedFile::fake()->createWithContent('evidence.txt', 'EVIDENCE BYTES');

        $share = app(CreateSecureShareAction::class)->execute(
            passcode: self::PASSCODE,
            text: 'gone in a flash',
            attachments: [$file],
            options: ['burn_after_reading' => true],
        );

        $media = $share->getMedia('secure-attachments')->first();
        $relativePath = $media->getPathRelativeToRoot();
        Storage::disk('test')->assertExists($relativePath);

        // Sync queue connection in tests: the delete job runs inline.
        $result = app(UnlockSecureShareAction::class)->execute($share->share_token, self::PASSCODE);

        $this->assertSame('gone in a flash', $result['plaintext']);
        $this->assertNull(SecureShare::find($share->id), 'Burned share must be deleted');
        Storage::disk('test')->assertMissing($relativePath, 'Media files must be deleted too');
    }

    #[Test]
    public function every_failure_mode_carries_the_identical_generic_message(): void
    {
        $messages = [];

        // Unknown token.
        try {
            app(UnlockSecureShareAction::class)->execute(str_repeat('0', 64), 'any-passcode!');
            $this->fail();
        } catch (ShareUnlockException $e) {
            $messages[] = $e->getMessage();
        }

        // Wrong passcode.
        $share = $this->createShare();
        try {
            app(UnlockSecureShareAction::class)->execute($share->share_token, 'wrong-passcode-123');
            $this->fail();
        } catch (ShareUnlockException $e) {
            $messages[] = $e->getMessage();
        }

        // Expired share.
        $expired = $this->createShare(['expires_at' => now()->subMinute()]);
        try {
            app(UnlockSecureShareAction::class)->execute($expired->share_token, self::PASSCODE);
            $this->fail();
        } catch (ShareUnlockException $e) {
            $messages[] = $e->getMessage();
        }

        $this->assertCount(3, $messages);
        $this->assertSame([$messages[0], $messages[0]], [$messages[1], $messages[2]]);
    }
}
