<?php

namespace Database\Seeders;

use App\Enums\AmountSign;
use App\Enums\MatchField;
use App\Enums\MatchType;
use App\Enums\RuleSource;
use App\Models\Category;
use App\Models\CategoryRule;
use Illuminate\Database\Seeder;

class CategoryRuleSeeder extends Seeder
{
    /**
     * Port of src/db/seed-category-rules.ts — keep behavior in sync with that script.
     *
     * Requires CategorySeeder to have run first so categorySlug lookups
     * resolve. Upserts on the (pattern, match_field) unique constraint,
     * matching category_rules' `uq_category_rules_pattern_field`.
     */
    public function run(): void
    {
        $rules = require database_path('data/default-category-rules.php');

        /** @var \Illuminate\Support\Collection<string, string> $categoryIdBySlug */
        $categoryIdBySlug = Category::query()->pluck('id', 'slug');

        $seeded = 0;
        $skipped = 0;

        foreach ($rules as $rule) {
            $categoryId = $categoryIdBySlug[$rule['categorySlug']] ?? null;

            if ($categoryId === null) {
                $this->command?->warn(sprintf(
                    'Skipping rule "%s": unknown category slug "%s"',
                    $rule['pattern'],
                    $rule['categorySlug'],
                ));
                $skipped++;

                continue;
            }

            $amountSign = AmountSign::from($rule['amountSign'] ?? 'any');
            $priority = $rule['priority'] ?? 100;

            $existing = CategoryRule::query()
                ->where('pattern', $rule['pattern'])
                ->where('match_field', MatchField::Name->value)
                ->first();

            if ($existing) {
                $existing->update([
                    'amount_sign' => $amountSign,
                    'category_id' => $categoryId,
                    'priority' => $priority,
                    'source' => RuleSource::Default,
                ]);
            } else {
                CategoryRule::create([
                    'pattern' => $rule['pattern'],
                    'match_field' => MatchField::Name,
                    'match_type' => MatchType::Substring,
                    'amount_sign' => $amountSign,
                    'category_id' => $categoryId,
                    'priority' => $priority,
                    'source' => RuleSource::Default,
                ]);
            }

            $seeded++;
        }

        $this->command?->info(sprintf(
            'Category rules seeded: %d upserted, %d skipped (unknown category).',
            $seeded,
            $skipped,
        ));
    }
}
