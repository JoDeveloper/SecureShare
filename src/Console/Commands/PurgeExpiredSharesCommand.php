<?php

namespace Ncpd\SecureShare\Console\Commands;

use Illuminate\Console\Command;
use Ncpd\SecureShare\Enums\SecureShareStatus;
use Ncpd\SecureShare\Models\SecureShare;

final class PurgeExpiredSharesCommand extends Command
{
    protected $signature = 'secure-share:purge';

    protected $description = 'Mark expired/exhausted secure shares and delete burned + past-retention shares (with their files)';

    public function handle(): int
    {
        /** @var class-string<SecureShare> $model */
        $model = config('secure-share.model');
        $retentionCutoff = now()->subDays((int) config('secure-share.purge.retention_days', 7));

        // Status sweeps — cheap mass updates, no deletion yet.
        $expired = $model::query()
            ->where('status', SecureShareStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => SecureShareStatus::Expired->value, 'updated_at' => now()]);

        $exhausted = $model::query()
            ->where('status', SecureShareStatus::Active->value)
            ->whereNotNull('max_views')
            ->whereColumn('view_count', '>=', 'max_views')
            ->update(['status' => SecureShareStatus::Exhausted->value, 'updated_at' => now()]);

        // Deletions — model deletes cascade to media records and files.
        $burned = $this->deleteShares(
            $model::query()->where('status', SecureShareStatus::Burned->value)
        );

        $purged = $this->deleteShares(
            $model::query()
                ->whereIn('status', [
                    SecureShareStatus::Expired->value,
                    SecureShareStatus::Exhausted->value,
                ])
                ->where('updated_at', '<', $retentionCutoff)
        );

        $this->info("Marked {$expired} expired and {$exhausted} exhausted shares.");
        $this->info("Deleted {$burned} burned and {$purged} past-retention shares (media files included).");

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SecureShare>  $query
     */
    private function deleteShares($query): int
    {
        $deleted = 0;

        $query->chunkById(100, function (iterable $shares) use (&$deleted): void {
            foreach ($shares as $share) {
                $share->delete();
                $deleted++;
            }
        });

        return $deleted;
    }
}
