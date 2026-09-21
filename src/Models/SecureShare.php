<?php

namespace Ncpd\SecureShare\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ncpd\SecureShare\Enums\SecureShareStatus;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class SecureShare extends Model implements HasMedia
{
    use InteractsWithMedia;

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
}
