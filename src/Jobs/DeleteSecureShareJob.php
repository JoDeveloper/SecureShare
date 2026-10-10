<?php

namespace Jodeveloper\SecureShare\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Jodeveloper\SecureShare\Enums\SecureShareStatus;
use Jodeveloper\SecureShare\Models\SecureShare;

final class DeleteSecureShareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $shareToken) {}

    public function handle(): void
    {
        /** @var class-string<SecureShare> $model */
        $model = config('secure-share.model');

        $share = $model::query()->where('share_token', $this->shareToken)->first();

        if ($share === null || $share->status !== SecureShareStatus::Burned) {
            return;
        }

        if ($share->has_attachments
            && $share->download_until !== null
            && now()->lessThan($share->download_until)) {
            return;
        }

        $share->erase();
    }
}
