<?php

use App\Models\Category;
use App\Models\CategoryRule;
use App\Services\CategorizationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

function makeCategory(string $name, ?string $sourceDetailed = null): Category
{
    return Category::create([
        'slug' => Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'kind' => 'custom',
        'source_detailed' => $sourceDetailed,
        'is_active' => true,
    ]);
}

it('returns null when nothing matches', function () {
    $service = app(CategorizationService::class);

    expect($service->categorize(['sourceCategoryDetailed' => null, 'amount' => 10, 'name' => 'unmatched merchant']))
        ->toBeNull();
});

it('source_detailed passthrough wins over rules', function () {
    $sourceCategory = makeCategory('Groceries (source)', 'FOOD_AND_DRINK_GROCERIES');
    $ruleCategory = makeCategory('Dining');

    CategoryRule::create([
        'pattern' => 'whole foods',
        'match_field' => 'name',
        'match_type' => 'substring',
        'amount_sign' => 'any',
        'category_id' => $ruleCategory->id,
        'priority' => 10,
        'source' => 'default',
        'is_active' => true,
    ]);

    $service = app(CategorizationService::class);

    $result = $service->categorize([
        'sourceCategoryDetailed' => 'FOOD_AND_DRINK_GROCERIES',
        'amount' => 42.10,
        'name' => 'WHOLE FOODS MARKET',
    ]);

    expect($result)->toBe($sourceCategory->id);
});

it('skips an outflow rule when the amount is negative (inflow)', function () {
    $category = makeCategory('Paycheck');

    CategoryRule::create([
        'pattern' => 'employer',
        'match_field' => 'name',
        'match_type' => 'substring',
        'amount_sign' => 'outflow',
        'category_id' => $category->id,
        'priority' => 10,
        'source' => 'default',
        'is_active' => true,
    ]);

    $service = app(CategorizationService::class);

    $result = $service->categorize(['sourceCategoryDetailed' => null, 'amount' => -1000, 'name' => 'employer direct deposit']);

    expect($result)->toBeNull();
});

it('skips an inflow rule when the amount is positive (outflow)', function () {
    $category = makeCategory('Refunds');

    CategoryRule::create([
        'pattern' => 'refund',
        'match_field' => 'name',
        'match_type' => 'substring',
        'amount_sign' => 'inflow',
        'category_id' => $category->id,
        'priority' => 10,
        'source' => 'default',
        'is_active' => true,
    ]);

    $service = app(CategorizationService::class);

    $result = $service->categorize(['sourceCategoryDetailed' => null, 'amount' => 25.00, 'name' => 'store refund policy']);

    expect($result)->toBeNull();
});

it('matches an inflow rule when the amount is negative', function () {
    $category = makeCategory('Refunds 2');

    CategoryRule::create([
        'pattern' => 'refund2',
        'match_field' => 'name',
        'match_type' => 'substring',
        'amount_sign' => 'inflow',
        'category_id' => $category->id,
        'priority' => 10,
        'source' => 'default',
        'is_active' => true,
    ]);

    $service = app(CategorizationService::class);

    $result = $service->categorize(['sourceCategoryDetailed' => null, 'amount' => -25.00, 'name' => 'refund2 issued']);

    expect($result)->toBe($category->id);
});

it('applies first-match-wins ordering by priority', function () {
    $highPriorityCategory = makeCategory('Coffee shops');
    $lowPriorityCategory = makeCategory('General dining');

    CategoryRule::create([
        'pattern' => 'blue bottle coffee',
        'match_field' => 'name',
        'match_type' => 'substring',
        'amount_sign' => 'any',
        'category_id' => $lowPriorityCategory->id,
        'priority' => 100,
        'source' => 'default',
        'is_active' => true,
    ]);
    CategoryRule::create([
        'pattern' => 'coffee',
        'match_field' => 'name',
        'match_type' => 'substring',
        'amount_sign' => 'any',
        'category_id' => $highPriorityCategory->id,
        'priority' => 1,
        'source' => 'user',
        'is_active' => true,
    ]);

    $service = app(CategorizationService::class);

    $result = $service->categorize(['sourceCategoryDetailed' => null, 'amount' => 5.00, 'name' => 'blue bottle coffee']);

    expect($result)->toBe($highPriorityCategory->id);
});

it('does not let an empty pattern match everything', function () {
    $category = makeCategory('Catch all');

    // Directly manipulate via the model to bypass any future validation that
    // might block empty patterns — the service-level guard is what's under test.
    $rule = CategoryRule::create([
        'pattern' => 'zzz-not-empty-for-db',
        'match_field' => 'name',
        'match_type' => 'substring',
        'amount_sign' => 'any',
        'category_id' => $category->id,
        'priority' => 1,
        'source' => 'default',
        'is_active' => true,
    ]);
    $rule->pattern = '';
    $rule->saveQuietly(); // saveQuietly: exercise categorize()'s own guard, not the observer's flush.

    Cache::forget('categorization:ruleset:v1');

    $service = app(CategorizationService::class);
    $result = $service->categorize(['sourceCategoryDetailed' => null, 'amount' => 5.00, 'name' => 'literally anything']);

    expect($result)->toBeNull();
});

it('invalidates the cache when a CategoryRule is saved, without an explicit reloadCache() call', function () {
    $category = makeCategory('Streaming');

    $service = app(CategorizationService::class);

    // Warm the cache with no matching rule yet.
    expect($service->categorize(['sourceCategoryDetailed' => null, 'amount' => 15.00, 'name' => 'netflix subscription']))
        ->toBeNull();
    expect(Cache::has('categorization:ruleset:v1'))->toBeTrue();

    CategoryRule::create([
        'pattern' => 'netflix',
        'match_field' => 'name',
        'match_type' => 'substring',
        'amount_sign' => 'any',
        'category_id' => $category->id,
        'priority' => 10,
        'source' => 'user',
        'is_active' => true,
    ]);

    // The observer's saved() hook should have flushed the cache lazily.
    expect(Cache::has('categorization:ruleset:v1'))->toBeFalse();

    $result = $service->categorize(['sourceCategoryDetailed' => null, 'amount' => 15.00, 'name' => 'netflix subscription']);
    expect($result)->toBe($category->id);
});

it('invalidates the cache when a CategoryRule is deleted', function () {
    $category = makeCategory('Gym');

    $rule = CategoryRule::create([
        'pattern' => 'anytime fitness',
        'match_field' => 'name',
        'match_type' => 'substring',
        'amount_sign' => 'any',
        'category_id' => $category->id,
        'priority' => 10,
        'source' => 'user',
        'is_active' => true,
    ]);

    $service = app(CategorizationService::class);
    expect($service->categorize(['sourceCategoryDetailed' => null, 'amount' => 40, 'name' => 'anytime fitness monthly']))
        ->toBe($category->id);

    $rule->delete();

    expect(Cache::has('categorization:ruleset:v1'))->toBeFalse();
    expect($service->categorize(['sourceCategoryDetailed' => null, 'amount' => 40, 'name' => 'anytime fitness monthly']))
        ->toBeNull();
});
