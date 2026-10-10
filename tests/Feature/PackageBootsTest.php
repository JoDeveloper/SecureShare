<?php

namespace Jodeveloper\SecureShare\Tests\Feature;

use Jodeveloper\SecureShare\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PackageBootsTest extends TestCase
{
    #[Test]
    public function config_is_registered_with_expected_defaults(): void
    {
        $this->assertSame(8, config('secure-share.passcode.min_length'));
        $this->assertSame('secure-share', config('secure-share.route.prefix'));
        $this->assertSame(['web'], config('secure-share.route.middleware'));
        $this->assertSame('6,1', config('secure-share.throttle'));
        $this->assertSame(600, config('secure-share.unlock_ttl'));
        $this->assertNull(config('secure-share.disk'));
    }

    #[Test]
    public function model_config_points_at_the_package_model(): void
    {
        $this->assertSame(
            \Jodeveloper\SecureShare\Models\SecureShare::class,
            config('secure-share.model')
        );
    }
}
