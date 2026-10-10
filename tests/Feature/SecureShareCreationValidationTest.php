<?php

namespace Jodeveloper\SecureShare\Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Jodeveloper\SecureShare\Actions\CreateSecureShareAction;
use Jodeveloper\SecureShare\Enums\SecureShareStatus;
use Jodeveloper\SecureShare\Models\SecureShare;
use Jodeveloper\SecureShare\Services\SecretCipher;
use Jodeveloper\SecureShare\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SecureShareCreationValidationTest extends TestCase
{
    private const PASSCODE = 'correct-horse-battery';

    #[Test]
    public function rejects_an_oversized_uploaded_attachment(): void
    {
        config(['secure-share.max_upload_kb' => 1]);

        try {
            app(CreateSecureShareAction::class)->execute(
                passcode: self::PASSCODE,
                attachments: [UploadedFile::fake()->createWithContent('large.txt', str_repeat('x', 1025))],
            );
            $this->fail('Expected a validation exception.');
        } catch (ValidationException) {
            // Expected.
        }

        $this->assertDatabaseCount('secure_shares', 0);
    }

    #[Test]
    public function rejects_an_oversized_stored_attachment(): void
    {
        config(['secure-share.max_upload_kb' => 1]);
        Storage::fake('source');
        Storage::disk('source')->put('records/large.txt', str_repeat('x', 1025));

        try {
            app(CreateSecureShareAction::class)->execute(
                passcode: self::PASSCODE,
                attachments: [[
                    'disk' => 'source',
                    'path' => 'records/large.txt',
                    'name' => 'large.txt',
                ]],
            );
            $this->fail('Expected a validation exception.');
        } catch (ValidationException) {
            // Expected.
        }

        $this->assertDatabaseCount('secure_shares', 0);
    }

    #[Test]
    public function rejects_a_missing_stored_attachment(): void
    {
        Storage::fake('source');

        try {
            app(CreateSecureShareAction::class)->execute(
                passcode: self::PASSCODE,
                attachments: [[
                    'disk' => 'source',
                    'path' => 'records/missing.txt',
                    'name' => 'missing.txt',
                ]],
            );
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('could not be read', $exception->errors()['attachments.0'][0]);
        }

        $this->assertDatabaseCount('secure_shares', 0);
    }

    #[Test]
    public function stores_an_encrypted_copy_without_removing_the_stored_source(): void
    {
        Storage::fake('source');
        Storage::disk('source')->put('records/report.txt', 'SOURCE PLAINTEXT');

        $share = app(CreateSecureShareAction::class)->execute(
            passcode: self::PASSCODE,
            attachments: [[
                'disk' => 'source',
                'path' => 'records/report.txt',
                'name' => '/unsafe/path/report.txt',
                'mime' => 'text/plain',
            ]],
        );

        $media = $share->refresh()->getMedia('secure-attachments')->first();

        $this->assertNotNull($media);
        $this->assertSame('report.txt', $media->custom_properties['original_name']);
        $this->assertSame('text/plain', $media->custom_properties['original_mime']);
        $this->assertSame(strlen('SOURCE PLAINTEXT'), $media->custom_properties['original_size']);
        $this->assertSame(
            'SOURCE PLAINTEXT',
            SecretCipher::decrypt(Storage::disk('test')->get($media->getPathRelativeToRoot()), self::PASSCODE),
        );
        Storage::disk('source')->assertExists('records/report.txt');
        $this->assertSame('SOURCE PLAINTEXT', Storage::disk('source')->get('records/report.txt'));
    }

    #[Test]
    public function rejects_invalid_view_limits_and_overlong_passcodes(): void
    {
        foreach ([0, 1.5] as $maxViews) {
            try {
                app(CreateSecureShareAction::class)->execute(
                    passcode: self::PASSCODE,
                    text: 'body',
                    options: ['max_views' => $maxViews],
                );
                $this->fail('Expected max_views validation to fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('max_views', $exception->errors());
            }
        }

        $this->expectException(ValidationException::class);

        app(CreateSecureShareAction::class)->execute(
            passcode: str_repeat('p', 1025),
            text: 'body',
        );
    }

    #[Test]
    public function failed_storage_does_not_leave_an_accessible_share_or_issue_sql_delete(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower(trim($query->sql));
        });

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->zeroOrMoreTimes()->andReturn(true);
        Storage::shouldReceive('disk')->with('failing')->andReturn($disk);
        config(['secure-share.disk' => 'failing']);

        try {
            app(CreateSecureShareAction::class)->execute(
                passcode: self::PASSCODE,
                attachments: [UploadedFile::fake()->createWithContent('failure.txt', 'failure')],
            );
            $this->fail('Expected the storage failure to be rethrown.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('Unable to write encrypted attachment', $exception->getMessage());
        }

        $this->assertSame(0, SecureShare::query()->where('status', SecureShareStatus::Active->value)->count());
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_starts_with($sql, 'delete')));
    }

    #[Test]
    public function a_late_storage_failure_erases_prior_encrypted_copies_and_preserves_sources(): void
    {
        Storage::fake('source');
        $sourceDisk = Storage::disk('source');
        $sourceDisk->put('records/first.txt', 'FIRST SOURCE');
        $sourceDisk->put('records/second.txt', 'SECOND SOURCE');

        $writtenPaths = [];
        $deletedPaths = [];
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->twice()->andReturnUsing(
            function (string $path) use (&$writtenPaths): bool {
                $writtenPaths[] = $path;

                return count($writtenPaths) === 1;
            }
        );
        $disk->shouldReceive('delete')->zeroOrMoreTimes()->andReturnUsing(
            function (string $path) use (&$deletedPaths): bool {
                $deletedPaths[] = $path;

                return true;
            }
        );
        Storage::shouldReceive('disk')->with('failing')->andReturn($disk);
        Storage::shouldReceive('disk')->with('source')->andReturn($sourceDisk);
        config(['secure-share.disk' => 'failing']);

        try {
            app(CreateSecureShareAction::class)->execute(
                passcode: self::PASSCODE,
                attachments: [
                    [
                        'disk' => 'source',
                        'path' => 'records/first.txt',
                        'name' => 'first.txt',
                    ],
                    [
                        'disk' => 'source',
                        'path' => 'records/second.txt',
                        'name' => 'second.txt',
                    ],
                ],
            );
            $this->fail('Expected the second storage write to fail.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('Unable to write encrypted attachment', $exception->getMessage());
        }

        $this->assertCount(2, $writtenPaths);
        $this->assertContains($writtenPaths[0], $deletedPaths);
        $this->assertSame('FIRST SOURCE', $sourceDisk->get('records/first.txt'));
        $this->assertSame('SECOND SOURCE', $sourceDisk->get('records/second.txt'));
    }
}
