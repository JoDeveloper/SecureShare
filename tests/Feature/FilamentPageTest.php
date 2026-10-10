<?php

namespace Jodeveloper\SecureShare\Tests\Feature;

use Livewire\Livewire;
use Jodeveloper\SecureShare\Enums\SecureShareStatus;
use Jodeveloper\SecureShare\Filament\Pages\CreateSecureShare;
use Jodeveloper\SecureShare\Models\SecureShare;
use Jodeveloper\SecureShare\Tests\Fixtures\TestUser;
use Jodeveloper\SecureShare\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FilamentPageTest extends TestCase
{
    private const PASSCODE = 'panel-created-passcode';

    #[Test]
    public function plugin_registers_the_page_in_the_panel(): void
    {
        $user = TestUser::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'secret-hash',
        ]);

        $this->actingAs($user)
            ->get('/admin/create-secure-share')
            ->assertOk();
    }

    #[Test]
    public function guests_are_redirected_to_the_panel_login(): void
    {
        $this->get('/admin/create-secure-share')
            ->assertRedirect('/admin/login');
    }

    #[Test]
    public function page_renders_the_expected_form_fields(): void
    {
        $user = TestUser::create([
            'name' => 'Admin',
            'email' => 'fields@example.com',
            'password' => 'secret-hash',
        ]);

        $response = $this->actingAs($user)->get('/admin/create-secure-share');

        $response->assertOk();
        $response->assertSee(__('secure-share::messages.passcode'), false);
        $response->assertSee(__('secure-share::messages.burn_after_reading'), false);
        $response->assertSee(__('secure-share::messages.max_views'), false);
    }

    #[Test]
    public function creating_a_share_through_the_page_persists_and_exposes_the_link(): void
    {
        $user = TestUser::create([
            'name' => 'Admin',
            'email' => 'create@example.com',
            'password' => 'secret-hash',
        ]);
        $this->actingAs($user);

        Livewire::test(CreateSecureShare::class)
            ->fillForm([
                'passcode' => self::PASSCODE,
                'passcode_confirmation' => self::PASSCODE,
                'text' => 'made in the panel',
                'expires_in' => '24h',
                'burn_after_reading' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertSet('createdToken', fn (?string $token): bool => is_string($token) && strlen($token) === 64);

        $share = SecureShare::query()->sole();

        $this->assertSame('made in the panel', \Jodeveloper\SecureShare\Services\SecretCipher::decrypt($share->ciphertext, self::PASSCODE));
        $this->assertSame($user->id, $share->created_by);
        $this->assertTrue($share->burn_after_reading);
        $this->assertSame(SecureShareStatus::Active, $share->status);
        $this->assertTrue($share->expires_at->isFuture());
    }

    #[Test]
    public function page_rejects_empty_shares_with_a_notification(): void
    {
        $user = TestUser::create([
            'name' => 'Admin',
            'email' => 'empty@example.com',
            'password' => 'secret-hash',
        ]);
        $this->actingAs($user);

        Livewire::test(CreateSecureShare::class)
            ->fillForm([
                'passcode' => self::PASSCODE,
                'passcode_confirmation' => self::PASSCODE,
                'text' => '',
            ])
            ->call('create');

        $this->assertSame(0, SecureShare::count());
    }
}
