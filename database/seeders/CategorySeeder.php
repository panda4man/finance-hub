<?php

namespace Database\Seeders;

use App\Enums\CategoryKind;
use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Port of src/db/seed-categories.ts — keep behavior in sync with that script.
     *
     * Two passes: first upsert one top-level category per distinct
     * `primary` code, then upsert a child category per detailed entry,
     * parented to the matching primary. Slugs are the lowercased source
     * codes verbatim (e.g. `food_and_drink_coffee`), not Str::slug()
     * output — category_rules.pattern lookups (and the default rule data
     * in default-category-rules.php) depend on this exact underscore
     * format matching categories.slug.
     */
    public function run(): void
    {
        $taxonomy = require database_path('data/default-category-taxonomy.php');

        $primaries = collect($taxonomy)->pluck('primary')->unique()->values();

        $parentIdByPrimary = [];

        foreach ($primaries as $primary) {
            $slug = strtolower($primary);
            $existing = Category::query()->where('slug', $slug)->first();

            if ($existing) {
                $existing->update(['name' => $this->humanize($primary)]);
                $parentIdByPrimary[$primary] = $existing->id;

                continue;
            }

            $category = Category::create([
                'slug' => $slug,
                'name' => $this->humanize($primary),
                'kind' => CategoryKind::SourceProvided,
                'source_primary' => $primary,
                'source_detailed' => null,
            ]);

            $parentIdByPrimary[$primary] = $category->id;
        }

        foreach ($taxonomy as $entry) {
            $slug = strtolower($entry['detailed']);
            $name = $this->humanize(str_replace($entry['primary'].'_', '', $entry['detailed']));
            $parentId = $parentIdByPrimary[$entry['primary']] ?? null;

            $existing = Category::query()->where('slug', $slug)->first();

            if ($existing) {
                $existing->update([
                    'parent_id' => $parentId,
                    'name' => $name,
                    'source_primary' => $entry['primary'],
                ]);

                continue;
            }

            Category::create([
                'parent_id' => $parentId,
                'slug' => $slug,
                'name' => $name,
                'kind' => CategoryKind::SourceProvided,
                'source_primary' => $entry['primary'],
                'source_detailed' => $entry['detailed'],
            ]);
        }

        $total = Category::query()->where('kind', CategoryKind::SourceProvided->value)->count();

        $this->command?->info(sprintf(
            'Category taxonomy seeded: %d primary, %d source_provided total.',
            $primaries->count(),
            $total,
        ));
    }

    /**
     * Matches the TS `humanize()` in src/db/seed-categories.ts exactly:
     * split on `_`, keep each word's first character as-is and lowercase
     * the rest, rejoin with spaces. Deliberately not a "smart" title-case
     * (e.g. "TV_AND_MOVIES" becomes "Tv And Movies", not "TV And Movies").
     */
    private function humanize(string $code): string
    {
        return collect(explode('_', $code))
            ->map(fn (string $word) => mb_substr($word, 0, 1).mb_strtolower(mb_substr($word, 1)))
            ->implode(' ');
    }
}
