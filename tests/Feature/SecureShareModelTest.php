<?php

namespace Ncpd\SecureShare\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Ncpd\SecureShare\Enums\SecureShareStatus;
use Ncpd\SecureShare\Models\SecureShare;
use Ncpd\SecureShare\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SecureShareModelTest extends TestCase
{
    #[Test]
    public function migrations_create_secure_shares_and_media_tables(): void
    {
        $this->assertTrue(Schema::hasTable('secure_shares'));
        $this->assertTrue(Schema::hasTable('media'));

        $columns = [
            'id', 'share_token', 'ciphertext', 'has_attachments', 'max_views',
            'view_count', 'burn_after_reading', 'expires_at', 'failed_attempts',
            'locked_until', 'created_by', 'status', 'created_at', 'updated_at',
        ];

        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn('secure_shares', $column),
                "Missing column [{$column}] on secure_shares"
            );
        }
    }

    #[Test]
    public function status_is_cast_to_the_enum_and_defaults_to_active(): void
    {
        $share = SecureShare::create(['share_token' => str_repeat('a', 64)]);

        $this->assertInstanceOf(SecureShareStatus::class, $share->status);
        $this->assertSame(SecureShareStatus::Active, $share->status);
        $this->assertSame(0, $share->view_count);
        $this->assertFalse($share->has_attachments);
        $this->assertFalse($share->burn_after_reading);
        $this->assertNull($share->expires_at);
        $this->assertNull($share->max_views);
    }

    #[Test]
    public function creator_relation_resolves_the_configured_user_model(): void
    {
        $user = \Ncpd\SecureShare\Tests\Fixtures\TestUser::create([
            'name' => 'Joe',
            'email' => 'joe@example.com',
            'password' => 'secret-hash',
        ]);

        $share = SecureShare::create([
            'share_token' => str_repeat('b', 64),
            'created_by' => $user->id,
        ]);

        $this->assertTrue($share->creator->is($user));

        $user->delete();
        $this->assertNull($share->refresh()->created_by);
    }
}
