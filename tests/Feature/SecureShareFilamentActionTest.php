<?php

namespace Ncpd\SecureShare\Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Ncpd\SecureShare\Filament\Schemas\SecureShareForm;
use Ncpd\SecureShare\Models\SecureShare;
use Ncpd\SecureShare\Services\SecretCipher;
use Ncpd\SecureShare\Tests\Fixtures\SecureShareActionPage;
use Ncpd\SecureShare\Tests\Fixtures\TestUser;
use Ncpd\SecureShare\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SecureShareFilamentActionTest extends TestCase
{
    private const PASSCODE = 'action-passcode';

    #[Test]
    public function creates_an_encrypted_share_and_clears_mounted_action_data(): void
    {
        $user = TestUser::create([
            'name' => 'Action user',
            'email' => 'action@example.com',
            'password' => 'secret-hash',
        ]);

        config()->set('auth.guards.test-panel', [
            'driver' => 'session',
            'provider' => 'users',
        ]);
        Filament::getDefaultPanel()->authGuard('test-panel');
        $this->actingAs($user, 'test-panel');

        $component = Livewire::test(SecureShareActionPage::class)
            ->callAction('secureShare', [
                'passcode' => self::PASSCODE,
                'passcode_confirmation' => self::PASSCODE,
                'text' => 'created through a reusable action',
                'expires_in' => '24h',
                'max_views' => 3,
            ]);

        $component
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertSet('mountedActions', []);

        $share = SecureShare::query()->sole();

        $this->assertSame($user->id, $share->created_by);
        $this->assertSame(
            'created through a reusable action',
            SecretCipher::decrypt($share->ciphertext, self::PASSCODE),
        );
        $this->assertSame(3, $share->max_views);
        $this->assertStringNotContainsString(self::PASSCODE, json_encode($component->instance()->mountedActions));
    }

    #[Test]
    public function native_authorization_prevents_submission(): void
    {
        $user = TestUser::create([
            'name' => 'Denied user',
            'email' => 'denied@example.com',
            'password' => 'secret-hash',
        ]);

        $this->actingAs($user);

        Livewire::test(SecureShareActionPage::class)
            ->callAction('deniedSecureShare', [
                'passcode' => self::PASSCODE,
                'passcode_confirmation' => self::PASSCODE,
                'text' => 'must not be persisted',
                'expires_in' => '24h',
            ])
            ->assertNotified(__('secure-share::messages.unauthorized'));

        $this->assertSame(0, SecureShare::count());
    }

    #[Test]
    public function attachment_resolver_receives_validated_selector_data(): void
    {
        $user = TestUser::create([
            'name' => 'Selector user',
            'email' => 'selector@example.com',
            'password' => 'secret-hash',
        ]);

        $this->actingAs($user);

        Livewire::test(SecureShareActionPage::class)
            ->callAction('selectorSecureShare', [
                'source_text' => 'selected server content',
                'passcode' => self::PASSCODE,
                'passcode_confirmation' => self::PASSCODE,
                'expires_in' => '24h',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $share = SecureShare::query()->sole();
        $media = $share->getFirstMedia('secure-attachments');

        $this->assertNotNull($media);
        $this->assertSame(
            'selected server content',
            SecretCipher::decrypt(stream_get_contents($media->stream()), self::PASSCODE),
        );
    }

    #[Test]
    public function expiry_resolver_rejects_unknown_values(): void
    {
        $this->assertNull(SecureShareForm::resolveExpiry('never'));

        $this->expectException(ValidationException::class);

        SecureShareForm::resolveExpiry('tomorrow');
    }
}
