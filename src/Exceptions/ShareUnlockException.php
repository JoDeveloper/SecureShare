<?php

namespace Jodeveloper\SecureShare\Exceptions;

use RuntimeException;

final class ShareUnlockException extends RuntimeException
{
    /**
     * Every unlock failure mode must be indistinguishable from the outside:
     * not-found, wrong passcode, expired, locked, exhausted — same message.
     */
    public static function generic(): self
    {
        return new self('This share is unavailable or the passcode is incorrect.');
    }
}
