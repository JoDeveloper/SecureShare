<?php

namespace Ncpd\SecureShare\Actions;

use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ncpd\SecureShare\Enums\SecureShareStatus;
use Ncpd\SecureShare\Models\SecureShare;
use Ncpd\SecureShare\Services\SecretCipher;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final class CreateSecureShareAction
{
    /**
     * @param  array<int, UploadedFile|array{disk: string, path: string, name: string, mime?: string}>  $attachments
     *                                                                                                                Stored-file descriptors must be resolved from authorized host records; never pass request arrays directly.
     * @param  array{created_by?: ?int, expires_at?: ?CarbonInterface|string, max_views?: ?int, burn_after_reading?: bool}  $options
     *
     * @throws ValidationException
     */
    public function execute(
        string $passcode,
        ?string $text = null,
        array $attachments = [],
        array $options = [],
    ): SecureShare {
        $minLength = (int) config('secure-share.passcode.min_length', 8);
        $maxUploadBytes = max(0, (int) config('secure-share.max_upload_kb', 10240)) * 1024;

        $validator = Validator::make(
            [
                'passcode' => $passcode,
                'text' => $text,
                'attachments' => $attachments,
                'max_views' => $options['max_views'] ?? null,
                'expires_at' => $options['expires_at'] ?? null,
                'created_by' => $options['created_by'] ?? null,
                'burn_after_reading' => $options['burn_after_reading'] ?? null,
            ],
            [
                'passcode' => ['required', 'string', 'min:'.$minLength, 'max:1024'],
                'text' => ['nullable', 'string', 'max:65535'],
                'attachments' => ['present', 'array'],
                'attachments.*' => [function (string $attribute, mixed $value, callable $fail): void {
                    if ($value instanceof UploadedFile) {
                        return;
                    }

                    if (! is_array($value)
                        || ! is_string($value['disk'] ?? null)
                        || trim($value['disk']) === ''
                        || ! is_string($value['path'] ?? null)
                        || trim($value['path']) === ''
                        || ! is_string($value['name'] ?? null)
                        || trim($value['name']) === '') {
                        $fail('Each attachment must be an uploaded file or a trusted stored-file descriptor.');

                        return;
                    }

                    if (array_key_exists('mime', $value) && ! is_string($value['mime'])) {
                        $fail('The stored-file descriptor MIME type must be a string.');
                    }
                }],
                'max_views' => ['nullable', 'integer', 'min:1'],
                'expires_at' => ['nullable', 'date'],
                'created_by' => ['nullable', 'integer'],
                'burn_after_reading' => ['nullable', 'boolean'],
            ],
        );

        $validator->after(function ($validator) use ($text, $attachments): void {
            if (blank($text) && count($attachments) === 0) {
                $validator->errors()->add(
                    'text',
                    'A secure share must contain text or at least one attachment.'
                );
            }
        });

        $validator->validate();

        foreach ($attachments as $index => $attachment) {
            $this->preflightAttachment($attachment, (int) $index, $maxUploadBytes);
        }

        $attachmentCount = count($attachments);

        /** @var class-string<SecureShare> $model */
        $model = config('secure-share.model');

        /** @var SecureShare|null $share */
        $share = null;

        try {
            /** @var SecureShare $share */
            $share = $model::create([
                'share_token' => bin2hex(random_bytes(32)),
                // Always encrypted — even for attachment-only shares — so the
                // passcode stays verifiable at unlock time (empty body decrypts
                // to an empty string).
                'ciphertext' => SecretCipher::encrypt($text ?? '', $passcode),
                'has_attachments' => $attachmentCount > 0,
                'max_views' => $options['max_views'] ?? null,
                'burn_after_reading' => (bool) ($options['burn_after_reading'] ?? false),
                'expires_at' => $options['expires_at'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);

            foreach ($attachments as $index => $attachment) {
                $resolvedAttachment = $this->readAttachment($attachment, (int) $index, $maxUploadBytes);
                $this->storeEncryptedAttachment($share, $resolvedAttachment, $passcode);
                unset($resolvedAttachment);
            }

            return $share;
        } catch (Throwable $exception) {
            if ($share instanceof SecureShare) {
                try {
                    $share->forceFill(['status' => SecureShareStatus::Burned->value])->saveQuietly();
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }

                try {
                    $share->erase();
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  UploadedFile|array{disk: string, path: string, name: string, mime?: string}  $attachment
     */
    private function preflightAttachment(UploadedFile|array $attachment, int $index, int $maxBytes): void
    {
        if ($attachment instanceof UploadedFile) {
            if (! $attachment->isValid()) {
                $this->invalidAttachment($index, 'The uploaded attachment is not valid.');
            }

            $size = $attachment->getSize();

            if ($size === null) {
                $this->invalidAttachment($index, 'The uploaded attachment size could not be determined.');
            }

            if ($size > $maxBytes) {
                $this->invalidAttachment($index, 'The attachment exceeds the configured maximum size.');
            }

            return;
        }

        try {
            $size = Storage::disk($attachment['disk'])->size($attachment['path']);
        } catch (Throwable) {
            $this->invalidAttachment($index, 'The stored attachment source could not be read.');
        }

        if ($size > $maxBytes) {
            $this->invalidAttachment($index, 'The attachment exceeds the configured maximum size.');
        }
    }

    /**
     * @param  UploadedFile|array{disk: string, path: string, name: string, mime?: string}  $attachment
     * @return array{name: string, mime: string, size: int, contents: string}
     */
    private function readAttachment(UploadedFile|array $attachment, int $index, int $maxBytes): array
    {
        $stream = false;

        if ($attachment instanceof UploadedFile) {
            if (! $attachment->isValid()) {
                $this->invalidAttachment($index, 'The uploaded attachment is not valid.');
            }

            $stream = @fopen($attachment->getPathname(), 'rb');
            $name = $attachment->getClientOriginalName();
            $mime = $attachment->getClientMimeType() ?: 'application/octet-stream';
        } else {
            try {
                $stream = Storage::disk($attachment['disk'])->readStream($attachment['path']);
            } catch (Throwable) {
                $this->invalidAttachment($index, 'The stored attachment source could not be read.');
            }

            $name = $attachment['name'];
            $mime = $attachment['mime'] ?? 'application/octet-stream';
        }

        if (! is_resource($stream)) {
            $this->invalidAttachment($index, 'The attachment source could not be read.');
        }

        try {
            $contents = stream_get_contents($stream, $maxBytes + 1);
        } finally {
            fclose($stream);
        }

        if ($contents === false) {
            $this->invalidAttachment($index, 'The attachment source could not be read.');
        }

        if (strlen($contents) > $maxBytes) {
            $this->invalidAttachment($index, 'The attachment exceeds the configured maximum size.');
        }

        return [
            'name' => $this->safeFilename($name),
            'mime' => str_replace(["\r", "\n"], '', $mime) ?: 'application/octet-stream',
            'size' => strlen($contents),
            'contents' => $contents,
        ];
    }

    /**
     * @param  array{name: string, mime: string, size: int, contents: string}  $attachment
     */
    private function storeEncryptedAttachment(SecureShare $share, array $attachment, string $passcode): void
    {
        $encrypted = SecretCipher::encrypt($attachment['contents'], $passcode);
        $fileName = bin2hex(random_bytes(16)).'.bin';
        $disk = $this->mediaDisk($share);

        /** @var class-string<Media> $mediaModel */
        $mediaModel = $share->getMediaModel();
        $media = new $mediaModel;
        $media->name = pathinfo($fileName, PATHINFO_FILENAME);
        $media->file_name = $fileName;
        $media->disk = $disk;
        $media->conversions_disk = $disk;
        $media->collection_name = 'secure-attachments';
        $media->mime_type = 'application/octet-stream';
        $media->size = strlen($encrypted);
        $media->custom_properties = [
            'original_name' => $attachment['name'],
            'original_mime' => $attachment['mime'],
            'original_size' => $attachment['size'],
            'encrypted' => true,
        ];
        $media->manipulations = [];
        $media->generated_conversions = [];
        $media->responsive_images = [];

        // FileAdder force-deletes the media row when a disk write returns false;
        // save and put separately so erase() can perform compensating cleanup.
        $share->media()->save($media);

        $path = $media->getPathRelativeToRoot();
        $written = Storage::disk($disk)->put($path, $encrypted);

        if (! $written) {
            throw new \RuntimeException("Unable to write encrypted attachment to disk [{$disk}].");
        }

        event(new MediaHasBeenAddedEvent($media));
    }

    private function mediaDisk(SecureShare $share): string
    {
        if (($disk = config('secure-share.disk')) !== null) {
            return (string) $disk;
        }

        $collection = $share->getMediaCollection('secure-attachments');

        return $collection?->diskName ?: (string) config('media-library.disk_name');
    }

    private function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = str_replace(["\0", "\r", "\n"], '', $name);

        return $name !== '' && ! in_array($name, ['.', '..'], true) ? $name : 'attachment';
    }

    private function invalidAttachment(int $index, string $message): never
    {
        throw ValidationException::withMessages([
            "attachments.{$index}" => $message,
        ]);
    }
}
