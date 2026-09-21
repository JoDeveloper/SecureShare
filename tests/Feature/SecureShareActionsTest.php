<?php

namespace Ncpd\SecureShare\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Ncpd\SecureShare\Actions\CreateSecureShareAction;
use Ncpd\SecureShare\Enums\SecureShareStatus;
use Ncpd\SecureShare\Models\SecureShare;
use Ncpd\SecureShare\Services\SecretCipher;
use Ncpd\SecureShare\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SecureShareActionsTest extends TestCase
{
    private const PASSCODE = 'correct-horse-battery';

    #[Test]
    public function creates_a_text_only_share_with_encrypted_body(): void
    {
        $share = app(CreateSecureShareAction::class)->execute(
            passcode: self::PASSCODE,
            text: 'the eagle lands at dawn',
        );

        $this->assertInstanceOf(SecureShare::class, $share);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $share->share_token);
        $this->assertSame(
            'the eagle lands at dawn',
            SecretCipher::decrypt($share->ciphertext, self::PASSCODE)
        );
        $this->assertStringNotContainsString('the eagle lands', $share->ciphertext);
        $this->assertStringNotContainsString(self::PASSCODE, $share->ciphertext);
        $this->assertFalse($share->has_attachments);
        $this->assertSame(0, $share->view_count);
        $this->assertSame(SecureShareStatus::Active, $share->status);
    }

    #[Test]
    public function generates_unique_tokens_per_share(): void
    {
        $action = app(CreateSecureShareAction::class);

        $first = $action->execute(passcode: self::PASSCODE, text: 'one');
        $second = $action->execute(passcode: self::PASSCODE, text: 'two');

        $this->assertNotSame($first->share_token, $second->share_token);
    }

    #[Test]
    public function stores_attachments_encrypted_with_original_metadata(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'field-report.txt',
            'PLAINTEXT ATTACHMENT BODY'
        );

        $share = app(CreateSecureShareAction::class)->execute(
            passcode: self::PASSCODE,
            text: 'see attachment',
            attachments: [$file],
        );

        $media = $share->refresh()->getMedia('secure-attachments');
        $this->assertCount(1, $media);

        $attachment = $media->first();

        // Stored under a random .bin name — the original name only lives in custom properties.
        $this->assertStringEndsWith('.bin', $attachment->file_name);
        $this->assertNotSame('field-report.txt', $attachment->file_name);

        // Metadata preserved for the download flow.
        $this->assertSame('field-report.txt', $attachment->custom_properties['original_name']);
        $this->assertSame(strlen('PLAINTEXT ATTACHMENT BODY'), $attachment->custom_properties['original_size']);
        $this->assertTrue($attachment->custom_properties['encrypted']);

        // On-disk bytes are ciphertext, not the plaintext body.
        Storage::disk('test')->assertExists($attachment->getPathRelativeToRoot());
        $rawBytes = Storage::disk('test')->get($attachment->getPathRelativeToRoot());
        $this->assertStringNotContainsString('PLAINTEXT ATTACHMENT BODY', $rawBytes);
        $this->assertSame(
            'PLAINTEXT ATTACHMENT BODY',
            SecretCipher::decrypt($rawBytes, self::PASSCODE)
        );

        $this->assertTrue($share->has_attachments);
    }

    #[Test]
    public function persists_lifetime_options(): void
    {
        $user = \Ncpd\SecureShare\Tests\Fixtures\TestUser::create([
            'name' => 'Creator',
            'email' => 'creator@example.com',
            'password' => 'secret-hash',
        ]);
        $expiresAt = now()->addDays(3);

        $share = app(CreateSecureShareAction::class)->execute(
            passcode: self::PASSCODE,
            text: 'limited',
            options: [
                'expires_at' => $expiresAt,
                'max_views' => 5,
                'burn_after_reading' => true,
                'created_by' => $user->id,
            ],
        );

        // SQLite stores second precision; compare at that granularity.
        $this->assertSame(
            $expiresAt->format('Y-m-d H:i:s'),
            $share->expires_at->format('Y-m-d H:i:s')
        );
        $this->assertSame(5, $share->max_views);
        $this->assertTrue($share->burn_after_reading);
        $this->assertSame($user->id, $share->created_by);
    }

    #[Test]
    public function rejects_passcode_below_configured_minimum(): void
    {
        $this->expectException(ValidationException::class);

        app(CreateSecureShareAction::class)->execute(passcode: 'short', text: 'body');
    }

    #[Test]
    public function rejects_share_with_no_text_and_no_attachments(): void
    {
        $this->expectException(ValidationException::class);

        app(CreateSecureShareAction::class)->execute(passcode: self::PASSCODE);
    }

    #[Test]
    public function allows_attachment_only_share_with_verification_ciphertext(): void
    {
        $file = UploadedFile::fake()->createWithContent('only-file.bin', 'BYTES');

        $share = app(CreateSecureShareAction::class)->execute(
            passcode: self::PASSCODE,
            attachments: [$file],
        );

        // A ciphertext is ALWAYS stored — even for attachment-only shares — so
        // the passcode remains verifiable at unlock time.
        $this->assertNotNull($share->ciphertext);
        $this->assertSame('', SecretCipher::decrypt($share->ciphertext, self::PASSCODE));
        $this->assertTrue($share->has_attachments);
        $this->assertCount(1, $share->getMedia('secure-attachments'));
    }
}
