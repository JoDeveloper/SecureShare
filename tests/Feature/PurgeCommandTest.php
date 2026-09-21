<?php

namespace Ncpd\SecureShare\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Ncpd\SecureShare\Actions\CreateSecureShareAction;
use Ncpd\SecureShare\Enums\SecureShareStatus;
use Ncpd\SecureShare\Models\SecureShare;
use Ncpd\SecureShare\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PurgeCommandTest extends TestCase
{
    private const PASSCODE = 'correct-horse-battery';

    #[Test]
    public function marks_expired_and_exhausted_active_shares_without_deleting_them(): void
    {
        $expired = $this->makeShare(['expires_at' => now()->subMinute()]);

        $exhausted = $this->makeShare(['max_views' => 2]);
        DB::table('secure_shares')->where('id', $exhausted->id)->update(['view_count' => 2]);

        $healthy = $this->makeShare(['expires_at' => now()->addDay()]);

        $this->artisan('secure-share:purge')->assertExitCode(0);

        $this->assertSame(SecureShareStatus::Expired, $expired->refresh()->status);
        $this->assertSame(SecureShareStatus::Exhausted, $exhausted->refresh()->status);
        $this->assertSame(SecureShareStatus::Active, $healthy->refresh()->status);

        // Status flips are not deletions — retention still applies.
        $this->assertNotNull(SecureShare::find($expired->id));
        $this->assertNotNull(SecureShare::find($exhausted->id));
    }

    #[Test]
    public function deletes_burned_shares_and_their_media_files(): void
    {
        $file = UploadedFile::fake()->createWithContent('burnt.txt', 'BURNT');
        $share = $this->makeShare([], [$file]);

        $media = $share->getMedia('secure-attachments')->first();
        $relativePath = $media->getPathRelativeToRoot();
        Storage::disk('test')->assertExists($relativePath);

        $share->status = SecureShareStatus::Burned;
        $share->save();

        $this->artisan('secure-share:purge')->assertExitCode(0);

        $this->assertNull(SecureShare::find($share->id));
        Storage::disk('test')->assertMissing($relativePath);
    }

    #[Test]
    public function deletes_past_retention_shares_but_keeps_recent_ones(): void
    {
        $recentFile = UploadedFile::fake()->createWithContent('recent.txt', 'R');
        $recent = $this->makeShare(['expires_at' => now()->subMinute()], [$recentFile]);

        $oldFile = UploadedFile::fake()->createWithContent('old.txt', 'O');
        $old = $this->makeShare(['expires_at' => now()->subDay()], [$oldFile]);

        // Simulate: a previous purge marked this share Expired more than
        // retention_days ago (the sweep refreshes updated_at when it flips).
        DB::table('secure_shares')->where('id', $old->id)->update([
            'status' => SecureShareStatus::Expired->value,
            'updated_at' => now()->subDays((int) config('secure-share.purge.retention_days') + 1),
        ]);

        $oldMediaPath = $old->getMedia('secure-attachments')->first()->getPathRelativeToRoot();

        $this->artisan('secure-share:purge')->assertExitCode(0);

        // Recent expiry: kept (status only).
        $this->assertSame(SecureShareStatus::Expired, $recent->refresh()->status);
        $this->assertNotNull(SecureShare::find($recent->id));

        // Past retention: share + files gone.
        $this->assertNull(SecureShare::find($old->id));
        Storage::disk('test')->assertMissing($oldMediaPath);
    }

    #[Test]
    public function purge_is_scheduled_hourly_by_default(): void
    {
        $this->artisan('secure-share:purge')->assertExitCode(0);

        $events = collect(app(Schedule::class)->events());

        $purgeEvents = $events->filter(
            fn ($event) => str_contains((string) $event->command, 'secure-share:purge')
        );

        $this->assertCount(1, $purgeEvents, 'Exactly one scheduled purge expected');
        $this->assertSame('0 * * * *', $purgeEvents->first()->expression);
    }

    private function makeShare(array $options = [], array $attachments = []): SecureShare
    {
        return app(CreateSecureShareAction::class)->execute(
            passcode: self::PASSCODE,
            text: 'purge me',
            attachments: $attachments,
            options: $options,
        );
    }
}
