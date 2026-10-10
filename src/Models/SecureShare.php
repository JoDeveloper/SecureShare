<?php

namespace Jodeveloper\SecureShare\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Jodeveloper\SecureShare\Enums\SecureShareStatus;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class SecureShare extends Model implements HasMedia
{
    use InteractsWithMedia;
    use SoftDeletes;

    protected $table = 'secure_shares';

    protected $guarded = [];

    protected $attributes = [
        'status' => SecureShareStatus::Active->value,
        'has_attachments' => false,
        'view_count' => 0,
        'failed_attempts' => 0,
        'burn_after_reading' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => SecureShareStatus::class,
            'has_attachments' => 'boolean',
            'burn_after_reading' => 'boolean',
            'expires_at' => 'datetime',
            'download_until' => 'datetime',
            'locked_until' => 'datetime',
            'max_views' => 'integer',
            'view_count' => 'integer',
            'failed_attempts' => 'integer',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('secure-attachments');
        // No registerMediaConversions() — encrypted bytes can't be thumbnailed.
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'created_by');
    }

    public function erase(): void
    {
        $this->forceFill([
            'ciphertext' => null,
            'download_until' => null,
            'status' => SecureShareStatus::Burned->value,
        ])->save();

        $mediaModel = $this->getMediaModel();
        $mediaUsesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($mediaModel), true);
        $mediaQuery = $this->media()->where('collection_name', 'secure-attachments');

        if ($mediaUsesSoftDeletes) {
            $mediaQuery->withTrashed();
        }

        foreach ($mediaQuery->get() as $media) {
            $properties = is_array($media->custom_properties) ? $media->custom_properties : [];

            if (! empty($properties['removed_at'])) {
                if ($mediaUsesSoftDeletes && method_exists($media, 'trashed') && ! $media->trashed()) {
                    $media->delete();
                }

                continue;
            }

            $disk = Storage::disk((string) $media->disk);
            $path = $media->getPathRelativeToRoot();

            if (! $disk->delete($path)) {
                throw new \RuntimeException("Unable to remove encrypted attachment [{$path}].");
            }

            $media->custom_properties = [
                ...$properties,
                'removed_at' => now()->toISOString(),
            ];
            $media->save();

            if ($mediaUsesSoftDeletes && method_exists($media, 'trashed') && ! $media->trashed()) {
                $media->delete();
            }
        }

        $this->delete();
    }
}
