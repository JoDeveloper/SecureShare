<?php

namespace Jodeveloper\SecureShare\Services;

use Jodeveloper\SecureShare\Exceptions\DecryptionFailedException;

final class SecretCipher
{
    /**
     * Salt + nonce + secretbox ciphertext, base64-encoded. Fresh randomness
     * per call, so encrypting the same input twice never yields the same
     * output. The key is derived from the passcode and never stored.
     */
    public static function encrypt(string $plaintext, string $passcode): string
    {
        $salt  = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passcode, $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        );
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        sodium_memzero($key);

        return base64_encode($salt . $nonce . $ciphertext);
    }

    public static function decrypt(string $encoded, string $passcode): string
    {
        $raw = base64_decode($encoded, true);
        throw_if($raw === false, DecryptionFailedException::class);

        $minimumLength = SODIUM_CRYPTO_PWHASH_SALTBYTES
            + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        throw_if(strlen($raw) < $minimumLength, DecryptionFailedException::class);

        $salt       = substr($raw, 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce      = substr($raw, SODIUM_CRYPTO_PWHASH_SALTBYTES, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passcode, $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        );
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        sodium_memzero($key);

        throw_if($plaintext === false, DecryptionFailedException::class);

        return $plaintext;
    }
}
