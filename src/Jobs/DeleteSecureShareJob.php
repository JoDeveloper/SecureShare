<?php

namespace Ncpd\SecureShare\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DeleteSecureShareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $shareToken)
    {
    }

    public function handle(): void
    {
        /** @var class-string<\Ncpd\SecureShare\Models\SecureShare> $model */
        $model = config('secure-share.model');

        // Deleting the model cascades to its media records and files.
        $model::query()->where('share_token', $this->shareToken)->first()?->delete();
    }
}
