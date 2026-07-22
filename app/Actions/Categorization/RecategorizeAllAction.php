<?php

declare(strict_types=1);

namespace App\Actions\Categorization;

use App\Models\Transaction;
use App\Services\CategorizationService;

/**
 * Recomputes category_id for every transaction using the current rule set —
 * e.g. after a bulk edit to category_rules. Only category_id (+ updated_at)
 * is touched; last_modified_at, user_category_id, user_notes, and is_hidden
 * are all user/sync-owned and must never be touched by this action.
 */
final class RecategorizeAllAction
{
    public function __construct(private readonly CategorizationService $categorization) {}

    /**
     * @return array{scanned: int, updated: int}
     */
    public function execute(): array
    {
        $scanned = 0;
        $updated = 0;

        Transaction::query()->chunkById((int) config('finance.recategorize_chunk'), function ($rows) use (&$scanned, &$updated): void {
            foreach ($rows as $row) {
                $scanned++;

                $categoryId = $this->categorization->categorize([
                    'name' => $row->name,
                    'amount' => $row->amount,
                ]);

                if ($categoryId === $row->category_id) {
                    continue;
                }

                $row->category_id = $categoryId;
                // Transaction has $timestamps = false, so save() won't touch
                // updated_at on its own — set it explicitly, and only save
                // the two columns we intend to change.
                $row->updated_at = now();
                $row->save();
                $updated++;
            }
        });

        return ['scanned' => $scanned, 'updated' => $updated];
    }
}
