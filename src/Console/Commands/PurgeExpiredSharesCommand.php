<?php

namespace Jodeveloper\SecureShare\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Jodeveloper\SecureShare\Enums\SecureShareStatus;
use Jodeveloper\SecureShare\Models\SecureShare;

final class PurgeExpiredSharesCommand extends Command
{
    protected $signature = 'secure-share:purge';

    protected $description = 'Mark expired/exhausted secure shares and erase burned + past-retention shares (with their files)';

    public function handle(): int
    {
        /** @var class-string<SecureShare> $model */
        $model = config('secure-share.model');
        $now = now();
        $retentionCutoff = $now->copy()->subDays((int) config('secure-share.purge.retention_days', 7));

        // Status sweeps — cheap mass updates, no deletion yet.
        $expired = $model::query()
            ->where('status', SecureShareStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->update(['status' => SecureShareStatus::Expired->value, 'updated_at' => $now]);

        $exhausted = $model::query()
            ->where('status', SecureShareStatus::Active->value)
            ->whereNotNull('max_views')
            ->whereColumn('view_count', '>=', 'max_views')
            ->update(['status' => SecureShareStatus::Exhausted->value, 'updated_at' => $now]);

        // Cleanup revokes access before removing encrypted files and soft-deleting rows.
        $burned = $this->deleteShares(
            $model::query()
                ->where('status', SecureShareStatus::Burned->value)
                ->where(function ($query) use ($now): void {
                    $query
                        ->where('has_attachments', false)
                        ->orWhereNull('download_until')
                        ->orWhere('download_until', '<=', $now);
                })
        );

        $purged = $this->deleteShares(
            $model::query()
                ->where(function ($query) use ($now): void {
                    $query
                        ->where('status', SecureShareStatus::Expired->value)
                        ->orWhere(function ($query) use ($now): void {
                            $query
                                ->where('status', SecureShareStatus::Exhausted->value)
                                ->where(function ($query) use ($now): void {
                                    $query
                                        ->whereNull('download_until')
                                        ->orWhere('download_until', '<=', $now);
                                });
                        });
                })
                ->where('updated_at', '<', $retentionCutoff)
        );

        $this->info("Marked {$expired} expired and {$exhausted} exhausted shares.");
        $this->info("Erased {$burned} burned and {$purged} past-retention shares (media files included).");

        return self::SUCCESS;
    }

    /**
     * @param  Builder<SecureShare>  $query
     */
    private function deleteShares($query): int
    {
        $deleted = 0;

        $query->chunkById(100, function (iterable $shares) use (&$deleted): void {
            foreach ($shares as $share) {
                $share->erase();
                $deleted++;
            }
        });

        return $deleted;
    }
}
