<?php

namespace Jodeveloper\SecureShare\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Jodeveloper\SecureShare\Actions\CreateSecureShareAction;
use Jodeveloper\SecureShare\Enums\SecureShareStatus;
use Jodeveloper\SecureShare\Models\SecureShare;
use Jodeveloper\SecureShare\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SecureShareFlowTest extends TestCase
{
    private const PASSCODE = 'correct-horse-battery';

    private function makeShare(array $options = [], array $attachments = [], string $text = 'the secret body'): SecureShare
    {
        return app(CreateSecureShareAction::class)->execute(
            passcode: self::PASSCODE,
            text: $text,
            attachments: $attachments,
            options: $options,
        );
    }

    #[Test]
    public function generic_message_resolves_from_package_translations(): void
    {
        $this->assertSame(
            'This share is unavailable or the passcode is incorrect.',
            __('secure-share::messages.unavailable')
        );
    }

    #[Test]
    public function unlock_and_download_routes_are_rate_limited(): void
    {
        $unlock = Route::getRoutes()->getByName('secure-share.unlock');
        $download = Route::getRoutes()->getByName('secure-share.download');

        $this->assertNotNull($unlock);
        $this->assertNotNull($download);
        $this->assertContains('throttle:secure-share', $unlock->gatherMiddleware());
        $this->assertContains('throttle:secure-share', $download->gatherMiddleware());
        $this->assertContains('web', $unlock->gatherMiddleware(), 'CSRF protection comes with the web group');
    }

    #[Test]
    public function full_flow_from_form_to_reveal_to_download(): void
    {
        $file = UploadedFile::fake()->createWithContent('invoice.txt', 'INVOICE PLAINTEXT 12345');
        $share = $this->makeShare([], [$file]);
        $media = $share->getMedia('secure-attachments')->first();

        // 1. The unlock form renders with CSRF protection.
        $form = $this->get("/secure-share/{$share->share_token}");
        $form->assertOk();
        $form->assertSee('name="passcode"', false);
        $form->assertSee('name="_token"', false);

        // 2. Posting the correct passcode reveals the secret and the download links.
        $reveal = $this->post("/secure-share/{$share->share_token}", ['passcode' => self::PASSCODE]);
        $reveal->assertOk();
        $reveal->assertSee('the secret body', false);
        $reveal->assertSee($media->uuid, false);
        $reveal->assertSee('invoice.txt', false);

        $grant = session('secure-share.unlocked.'.$share->share_token);
        $this->assertIsArray($grant);
        $this->assertNotSame(self::PASSCODE, $grant['pc']);
        $this->assertSame(self::PASSCODE, Crypt::decryptString($grant['pc']));

        // 3. The download streams the exact original bytes under the original name.
        $download = $this->get("/secure-share/{$share->share_token}/download/{$media->uuid}");
        $download->assertOk();
        $this->assertSame('INVOICE PLAINTEXT 12345', $download->streamedContent());
        $this->assertSame(
            'attachment; filename=invoice.txt',
            $download->headers->get('Content-Disposition')
        );
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
    }

    #[Test]
    public function unlock_rejects_a_share_at_its_exact_expiry_timestamp(): void
    {
        $share = $this->makeShare(['expires_at' => now()->addMinute()]);
        $expiresAt = $share->refresh()->expires_at;

        Carbon::setTestNow($expiresAt);
        $response = $this->post("/secure-share/{$share->share_token}", ['passcode' => self::PASSCODE]);
        Carbon::setTestNow();

        $response
            ->assertRedirect("/secure-share/{$share->share_token}")
            ->assertSessionHas('error', __('secure-share::messages.unavailable'));
        $this->assertSame(SecureShareStatus::Expired, $share->refresh()->status);
    }

    #[Test]
    public function download_rejects_a_live_grant_at_its_exact_expiry_timestamp(): void
    {
        $file = UploadedFile::fake()->createWithContent('grant.txt', 'GRANT');
        $share = $this->makeShare([], [$file]);
        $media = $share->getMedia('secure-attachments')->first();

        $this->post("/secure-share/{$share->share_token}", ['passcode' => self::PASSCODE])->assertOk();
        $downloadUntil = $share->refresh()->download_until;

        Carbon::setTestNow($downloadUntil);
        $response = $this->get("/secure-share/{$share->share_token}/download/{$media->uuid}");
        Carbon::setTestNow();

        $response
            ->assertRedirect("/secure-share/{$share->share_token}")
            ->assertSessionHas('error', __('secure-share::messages.unavailable'));
    }

    #[Test]
    public function download_rejects_a_live_grant_when_the_share_expires_at_the_boundary(): void
    {
        $file = UploadedFile::fake()->createWithContent('expiry.txt', 'EXPIRY');
        $share = $this->makeShare(['expires_at' => now()->addMinute()], [$file]);
        $media = $share->getMedia('secure-attachments')->first();

        $this->post("/secure-share/{$share->share_token}", ['passcode' => self::PASSCODE])->assertOk();
        $expiresAt = $share->refresh()->expires_at;

        Carbon::setTestNow($expiresAt);
        $response = $this->get("/secure-share/{$share->share_token}/download/{$media->uuid}");
        Carbon::setTestNow();

        $response
            ->assertRedirect("/secure-share/{$share->share_token}")
            ->assertSessionHas('error', __('secure-share::messages.unavailable'));
    }

    #[Test]
    public function unknown_token_returns_404_with_the_generic_message(): void
    {
        $response = $this->get('/secure-share/'.str_repeat('0', 64));

        $response->assertNotFound();
        $response->assertSee(__('secure-share::messages.unavailable'), false);
    }

    #[Test]
    public function wrong_passcode_redirects_back_with_the_identical_generic_message(): void
    {
        $share = $this->makeShare();

        $response = $this->from("/secure-share/{$share->share_token}")
            ->post("/secure-share/{$share->share_token}", ['passcode' => 'wrong-passcode-123']);

        $response->assertRedirect("/secure-share/{$share->share_token}");
        $response->assertSessionHas(
            'error',
            'This share is unavailable or the passcode is incorrect.'
        );

        // Following the redirect shows the same copy the 404 page shows.
        $followed = $this->get("/secure-share/{$share->share_token}");
        $followed->assertOk();
        $followed->assertSee(__('secure-share::messages.unavailable'), false);
    }

    #[Test]
    public function lockout_blocks_even_the_correct_passcode_over_http(): void
    {
        $share = $this->makeShare();

        for ($i = 0; $i < 5; $i++) {
            $this->post("/secure-share/{$share->share_token}", ['passcode' => 'wrong-passcode-123'])
                ->assertRedirect();
        }

        // 6th POST (at the '6,1' throttle limit) with the CORRECT passcode: rejected.
        $this->post("/secure-share/{$share->share_token}", ['passcode' => self::PASSCODE])
            ->assertRedirect()
            ->assertSessionHas('error', __('secure-share::messages.unavailable'));

        $this->assertTrue($share->refresh()->locked_until->isFuture());
    }

    #[Test]
    public function burn_after_reading_reveal_once_then_allows_first_attachment_download(): void
    {
        $file = UploadedFile::fake()->createWithContent('evidence.txt', 'EVIDENCE BYTES');
        $share = $this->makeShare(['burn_after_reading' => true], [$file]);

        $media = $share->getMedia('secure-attachments')->first();
        $relativePath = $media->getPathRelativeToRoot();
        Storage::disk('test')->assertExists($relativePath);

        $reveal = $this->post("/secure-share/{$share->share_token}", ['passcode' => self::PASSCODE]);
        $reveal->assertOk();
        $reveal->assertSee('the secret body', false);

        $this->assertSame(SecureShareStatus::Burned, $share->refresh()->status);
        Storage::disk('test')->assertExists($relativePath);

        $this->get("/secure-share/{$share->share_token}/download/{$media->uuid}")
            ->assertOk()
            ->assertStreamedContent('EVIDENCE BYTES');

        $this->post("/secure-share/{$share->share_token}", ['passcode' => self::PASSCODE])
            ->assertRedirect()
            ->assertSessionHas('error', __('secure-share::messages.unavailable'));

        $this->get("/secure-share/{$share->share_token}")->assertNotFound();
    }

    #[Test]
    public function cross_share_attachment_isolation(): void
    {
        $fileA = UploadedFile::fake()->createWithContent('a.txt', 'AAA');
        $fileB = UploadedFile::fake()->createWithContent('b.txt', 'BBB');

        $shareA = $this->makeShare([], [$fileA], 'share a body');
        $shareB = $this->makeShare([], [$fileB], 'share b body');

        $mediaB = $shareB->getMedia('secure-attachments')->first();

        // Unlock share A only.
        $this->post("/secure-share/{$shareA->share_token}", ['passcode' => self::PASSCODE])
            ->assertOk();

        // Attempting to download share B's file through share A's URL: 404.
        $this->get("/secure-share/{$shareA->share_token}/download/{$mediaB->uuid}")
            ->assertNotFound();
    }

    #[Test]
    public function download_requires_a_recent_unlock_flag(): void
    {
        $file = UploadedFile::fake()->createWithContent('doc.txt', 'DOC');
        $share = $this->makeShare([], [$file]);
        $media = $share->getMedia('secure-attachments')->first();
        $url = "/secure-share/{$share->share_token}/download/{$media->uuid}";

        // No unlock at all.
        $this->get($url)
            ->assertRedirect("/secure-share/{$share->share_token}")
            ->assertSessionHas('error', __('secure-share::messages.unavailable'));

        // Unlock, then let the flag go stale.
        $this->post("/secure-share/{$share->share_token}", ['passcode' => self::PASSCODE])->assertOk();

        $key = 'secure-share.unlocked.'.$share->share_token;
        $stale = session($key);
        $stale['at'] = now()->subSeconds((int) config('secure-share.unlock_ttl') + 1)->getTimestamp();
        session()->put($key, $stale);

        $this->get($url)
            ->assertRedirect("/secure-share/{$share->share_token}")
            ->assertSessionHas('error', __('secure-share::messages.unavailable'));
    }

    #[Test]
    public function passcode_is_never_persisted_in_db_files_or_logs(): void
    {
        $file = UploadedFile::fake()->createWithContent('canary.txt', 'CANARY');
        $share = $this->makeShare([], [$file]);

        $this->post("/secure-share/{$share->share_token}", ['passcode' => self::PASSCODE])->assertOk();

        // Database
        $rowJson = json_encode(SecureShare::find($share->id)->getAttributes());
        $this->assertStringNotContainsString(self::PASSCODE, $rowJson);
        $this->assertStringNotContainsString('the secret body', $rowJson);

        // Media file bytes
        $raw = Storage::disk('test')->get(
            $share->getMedia('secure-attachments')->first()->getPathRelativeToRoot()
        );
        $this->assertStringNotContainsString(self::PASSCODE, $raw);
        $this->assertStringNotContainsString('CANARY', $raw);

        // Logs
        $logPath = storage_path('logs/laravel.log');
        if (file_exists($logPath)) {
            $this->assertStringNotContainsString(self::PASSCODE, file_get_contents($logPath));
        }
    }
}
