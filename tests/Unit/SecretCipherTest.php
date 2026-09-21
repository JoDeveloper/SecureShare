<?php

namespace Ncpd\SecureShare\Tests\Unit;

use Ncpd\SecureShare\Exceptions\DecryptionFailedException;
use Ncpd\SecureShare\Services\SecretCipher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SecretCipherTest extends TestCase
{
    private const PASSCODE = 'correct-horse-battery';

    #[Test]
    public function round_trips_plaintext_of_various_shapes(): void
    {
        $payloads = [
            'short secret',
            '',
            str_repeat('x', 100_000),
            'يونيكود: نص عربي للاتجاه من اليمين إلى اليسار 🔐',
            "multi\nline\nwith\x00binary\xFF bytes",
        ];

        foreach ($payloads as $payload) {
            $encrypted = SecretCipher::encrypt($payload, self::PASSCODE);

            $this->assertSame($payload, SecretCipher::decrypt($encrypted, self::PASSCODE));
        }
    }

    #[Test]
    public function produces_standard_base64_output(): void
    {
        $encrypted = SecretCipher::encrypt('hello', self::PASSCODE);

        $this->assertSame($encrypted, base64_encode(base64_decode($encrypted, true)));
        $this->assertNotSame('hello', $encrypted);
    }

    #[Test]
    public function uses_fresh_salt_and_nonce_per_encryption(): void
    {
        $first = SecretCipher::encrypt('same input', self::PASSCODE);
        $second = SecretCipher::encrypt('same input', self::PASSCODE);

        $this->assertNotSame($first, $second, 'Each encryption must use fresh randomness');
    }

    #[Test]
    public function wrong_passcode_throws_decryption_failed(): void
    {
        $encrypted = SecretCipher::encrypt('top secret', self::PASSCODE);

        $this->expectException(DecryptionFailedException::class);

        SecretCipher::decrypt($encrypted, 'wrong-passcode-entirely');
    }

    #[Test]
    public function tampered_ciphertext_throws_decryption_failed(): void
    {
        $encrypted = SecretCipher::encrypt('top secret', self::PASSCODE);

        $raw = base64_decode($encrypted, true);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);

        $this->expectException(DecryptionFailedException::class);

        SecretCipher::decrypt(base64_encode($raw), self::PASSCODE);
    }

    #[Test]
    public function truncated_payload_throws_decryption_failed(): void
    {
        $encrypted = SecretCipher::encrypt('top secret', self::PASSCODE);

        $raw = base64_decode($encrypted, true);
        $truncated = substr($raw, 0, 10);

        $this->expectException(DecryptionFailedException::class);

        SecretCipher::decrypt(base64_encode($truncated), self::PASSCODE);
    }

    #[Test]
    public function invalid_base64_throws_decryption_failed(): void
    {
        $this->expectException(DecryptionFailedException::class);

        SecretCipher::decrypt('!!not-base64!!', self::PASSCODE);
    }
}
