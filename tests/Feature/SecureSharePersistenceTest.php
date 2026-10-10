<?php

namespace Jodeveloper\SecureShare\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Jodeveloper\SecureShare\Actions\CreateSecureShareAction;
use Jodeveloper\SecureShare\Enums\SecureShareStatus;
use Jodeveloper\SecureShare\Models\SecureShare;
use Jodeveloper\SecureShare\Tests\Fixtures\CustomSecureShare;
use Jodeveloper\SecureShare\Tests\Fixtures\SoftDeletingMedia;
use Jodeveloper\SecureShare\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class SecureSharePersistenceTest extends TestCase
{
    private const PASSCODE = 'correct-horse-battery';

    #[Test]
    public function erase_revokes_access_removes_the_object_and_soft_deletes_the_share(): void
    {
        $share = $this->makeShare([
            'expires_at' => now()->addHour(),
        ], [UploadedFile::fake()->createWithContent('secret.txt', 'SECRET')]);
        $media = $share->getMedia('secure-attachments')->first();
        $path = $media->getPathRelativeToRoot();
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower(trim($query->sql));
        });

        $share->erase();

        $erased = SecureShare::withTrashed()->find($share->id);
        $retainedMedia = Media::find($media->id);

        $this->assertNotNull($erased);
        $this->assertNotNull($erased->deleted_at);
        $this->assertNull($erased->ciphertext);
        $this->assertNull($erased->download_until);
        $this->assertSame(SecureShareStatus::Burned, $erased->status);
        $this->assertNotNull($retainedMedia);
        $this->assertNotEmpty($retainedMedia->custom_properties['removed_at']);
        Storage::disk('test')->assertMissing($path);
        $this->assertFalse(collect($queries)->contains(
            fn (string $sql): bool => str_starts_with($sql, 'delete')
        ));
    }

    #[Test]
    public function failed_object_removal_leaves_a_revoked_share_for_the_next_retry(): void
    {
        $share = $this->makeShare([], [UploadedFile::fake()->createWithContent('secret.txt', 'SECRET')]);
        $media = $share->getMedia('secure-attachments')->first();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->twice()->andReturn(false, true);
        Storage::shouldReceive('disk')->with((string) $media->disk)->twice()->andReturn($disk);

        try {
            $share->erase();
            $this->fail('Expected encrypted-object cleanup to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unable to remove encrypted attachment', $exception->getMessage());
        }

        $revoked = SecureShare::find($share->id);
        $this->assertNotNull($revoked);
        $this->assertSame(SecureShareStatus::Burned, $revoked->status);
        $this->assertNull($revoked->ciphertext);
        $this->assertNull($revoked->download_until);

        $share->erase();

        $this->assertNotNull(SecureShare::withTrashed()->find($share->id)?->deleted_at);
    }

    #[Test]
    public function purge_retries_an_expired_share_that_failed_cleanup(): void
    {
        $share = $this->makeShare([], [UploadedFile::fake()->createWithContent('secret.txt', 'SECRET')]);
        $media = $share->getMedia('secure-attachments')->first();
        DB::table('secure_shares')->where('id', $share->id)->update([
            'status' => SecureShareStatus::Expired->value,
            'updated_at' => now()->subDays((int) config('secure-share.purge.retention_days') + 1),
        ]);

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->twice()->andReturn(false, true);
        Storage::shouldReceive('disk')->with((string) $media->disk)->twice()->andReturn($disk);

        try {
            $this->artisan('secure-share:purge')->run();
            $this->fail('Expected the first purge attempt to fail cleanup.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unable to remove encrypted attachment', $exception->getMessage());
        }

        $this->assertSame(SecureShareStatus::Burned, SecureShare::find($share->id)->status);

        $this->artisan('secure-share:purge')->assertExitCode(0);
        $this->assertNull(SecureShare::find($share->id));
        $this->assertNotNull(SecureShare::withTrashed()->find($share->id)?->deleted_at);
    }

    #[Test]
    public function configured_share_model_can_be_extended_without_changing_the_table(): void
    {
        config(['secure-share.model' => CustomSecureShare::class]);

        $share = $this->makeShare();

        $this->assertInstanceOf(CustomSecureShare::class, $share);
        $this->assertSame('secure_shares', $share->getTable());

        $share->erase();

        $this->assertNotNull(CustomSecureShare::withTrashed()->find($share->id)?->deleted_at);
    }

    #[Test]
    public function configured_soft_deleting_media_is_excluded_from_download_resolution(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->softDeletes();
        });
        config(['media-library.media_model' => SoftDeletingMedia::class]);

        $share = $this->makeShare([], [UploadedFile::fake()->createWithContent('secret.txt', 'SECRET')]);
        $media = $share->getMedia('secure-attachments')->first();
        $path = $media->getPathRelativeToRoot();

        $this->post("/secure-share/{$share->share_token}", ['passcode' => self::PASSCODE])->assertOk();
        $media->delete();

        $this->get("/secure-share/{$share->share_token}/download/{$media->uuid}")
            ->assertNotFound();

        $share->erase();

        Storage::disk('test')->assertMissing($path);
        $this->assertNotNull(SoftDeletingMedia::withTrashed()->find($media->id)?->deleted_at);
    }

    #[Test]
    public function migration_and_composer_metadata_include_the_persistence_contract(): void
    {
        $this->assertTrue(Schema::hasColumn('secure_shares', 'download_until'));
        $this->assertTrue(Schema::hasColumn('secure_shares', 'deleted_at'));

        $composer = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertContains(
            'Jodeveloper\\SecureShare\\SecureShareServiceProvider',
            $composer['extra']['laravel']['providers'] ?? []
        );
    }

    private function makeShare(array $options = [], array $attachments = []): SecureShare
    {
        return app(CreateSecureShareAction::class)->execute(
            passcode: self::PASSCODE,
            text: 'persist me',
            attachments: $attachments,
            options: $options,
        );
    }
}
