<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Ordered rule cache + source_detailed→category map. categorize() is pure and
 * does zero DB I/O — the cache is invalidated (lazily) by CategoryRuleObserver
 * whenever a CategoryRule is saved/deleted, so every queue worker/web
 * process/scheduler process shares one ruleset via the `database` cache store.
 */
final class CategorizationService
{
    private const CACHE_KEY = 'categorization:ruleset:v1';

    /**
     * @return array{rules: list<array{patternLower: string, amountSign: string, categoryId: string}>, sourceMap: array<string, string>}
     */
    private function ruleset(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => $this->buildRuleset());
    }

    /**
     * The only method in this class that does DB I/O. Returns plain arrays
     * only — never cache enum objects or Eloquent models.
     *
     * @return array{rules: list<array{patternLower: string, amountSign: string, categoryId: string}>, sourceMap: array<string, string>}
     */
    private function buildRuleset(): array
    {
        $rules = CategoryRule::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get()
            ->map(fn (CategoryRule $r) => [
                'patternLower' => Str::lower($r->pattern),
                'amountSign' => $r->amount_sign->value,
                'categoryId' => $r->category_id,
            ])
            ->all();

        $sourceMap = Category::query()->whereNotNull('source_detailed')->pluck('id', 'source_detailed')->all();

        return ['rules' => $rules, 'sourceMap' => $sourceMap];
    }

    /**
     * Pure, zero-DB-I/O categorization. $input keys: sourceCategoryDetailed
     * (nullable string), amount (numeric), name (string).
     *
     * @param  array{sourceCategoryDetailed?: ?string, amount: mixed, name: string}  $input
     */
    public function categorize(array $input): ?string
    {
        $set = $this->ruleset();

        $src = $input['sourceCategoryDetailed'] ?? null;
        if ($src !== null && isset($set['sourceMap'][$src])) {
            return $set['sourceMap'][$src];
        }

        $amount = (float) $input['amount'];
        $name = Str::lower((string) $input['name']);

        foreach ($set['rules'] as $rule) {
            if ($rule['amountSign'] === 'outflow' && ! ($amount > 0)) {
                continue;
            }
            if ($rule['amountSign'] === 'inflow' && ! ($amount < 0)) {
                continue;
            }

            // Guard against an empty pattern matching everything. MatchField/
            // MatchType currently only support name/substring — if those
            // enums ever grow more cases, this loop is where to branch on them.
            if ($rule['patternLower'] !== '' && str_contains($name, $rule['patternLower'])) {
                return $rule['categoryId'];
            }
        }

        return null;
    }

    /**
     * Eager rebuild — recomputes and repopulates the cache immediately.
     *
     * @return array{rules: list<array{patternLower: string, amountSign: string, categoryId: string}>, sourceMap: array<string, string>}
     */
    public function reloadCache(): array
    {
        $fresh = $this->buildRuleset();
        Cache::forever(self::CACHE_KEY, $fresh);

        return $fresh;
    }

    /**
     * Lazy invalidation — what CategoryRuleObserver calls. Avoids N full
     * rebuilds on bulk mutations (seeders, future bulk Filament actions); the
     * next categorize() call rebuilds once, lazily.
     */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
