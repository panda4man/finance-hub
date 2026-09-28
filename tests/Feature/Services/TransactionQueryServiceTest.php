<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionQueryService;
use App\Support\Transactions\TransactionFilters;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

function tqsInstitution(string $name): Institution
{
    return Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-'.Str::random(8),
        'name' => $name,
    ]);
}

function tqsCategory(string $slug): Category
{
    return Category::create([
        'slug' => $slug,
        'name' => ucfirst($slug),
        'kind' => 'custom',
        'is_active' => true,
    ]);
}

function tqsService(): TransactionQueryService
{
    return app(TransactionQueryService::class);
}

// --- ownedQuery ---

it('returns only transactions on the user\'s connections', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $ownerTxn = Transaction::factory()->for(
        Account::factory()->for(Connection::factory(['user_id' => $owner->id]))
    )->create();
    Transaction::factory()->for(
        Account::factory()->for(Connection::factory(['user_id' => $stranger->id]))
    )->create();

    $results = tqsService()->ownedQuery($owner)->get();

    expect($results->pluck('id')->all())->toBe([$ownerTxn->id]);
});

it('excludes removed transactions from ownedQuery', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $active = Transaction::factory()->for($account)->create();
    Transaction::factory()->for($account)->removed()->create();

    $results = tqsService()->ownedQuery($user)->get();

    expect($results->pluck('id')->all())->toBe([$active->id]);
});

it('ownedQuery includes hidden and pending transactions without filtering', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    Transaction::factory()->for($account)->hidden()->create();
    Transaction::factory()->for($account)->pending()->create();

    $results = tqsService()->ownedQuery($user)->get();

    expect($results)->toHaveCount(2);
});

it('selects effective category and eager loads relations', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $source = tqsCategory('groceries');
    $override = tqsCategory('dining');

    Transaction::factory()->for($account)->create([
        'category_id' => $source->id,
        'user_category_id' => $override->id,
    ]);

    $result = tqsService()->ownedQuery($user)->first();

    expect($result->effective_category_slug)->toBe('dining');
    expect($result->relationLoaded('account'))->toBeTrue();
    expect($result->account->relationLoaded('institution'))->toBeTrue();
    expect($result->relationLoaded('category'))->toBeTrue();
    expect($result->relationLoaded('userCategory'))->toBeTrue();
});

// --- applyFilters: defaults & visibility ---

function tqsFiltered(User $user, array $filters = []): Collection
{
    $service = tqsService();

    return $service->applyFilters($service->ownedQuery($user), TransactionFilters::fromArray($filters))->get();
}

it('applies no filters beyond visibility defaults when filters are empty', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $visible = Transaction::factory()->for($account)->create();
    Transaction::factory()->for($account)->pending()->create();
    Transaction::factory()->for($account)->hidden()->create();

    $results = tqsFiltered($user);

    expect($results->pluck('id')->all())->toBe([$visible->id]);
});

it('includes pending only when include_pending is true', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    Transaction::factory()->for($account)->pending()->create();

    expect(tqsFiltered($user))->toHaveCount(0);
    expect(tqsFiltered($user, ['include_pending' => true]))->toHaveCount(1);
});

it('includes hidden only when include_hidden is true', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    Transaction::factory()->for($account)->hidden()->create();

    expect(tqsFiltered($user))->toHaveCount(0);
    expect(tqsFiltered($user, ['include_hidden' => true]))->toHaveCount(1);
});

it('still excludes removed rows even with both include flags', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    Transaction::factory()->for($account)->removed()->create();

    expect(tqsFiltered($user, ['include_pending' => true, 'include_hidden' => true]))->toHaveCount(0);
});

it('never returns stranger rows regardless of filters', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $strangerAccount = Account::factory()->for(Connection::factory(['user_id' => $stranger->id]))->create();
    $strangerInstitution = tqsInstitution('Stranger Bank');
    $strangerAccount->forceFill(['institution_id' => $strangerInstitution->id])->save();
    Transaction::factory()->for($strangerAccount)->create();

    $results = tqsFiltered($owner, [
        'account_ids' => [$strangerAccount->id],
        'institution' => $strangerInstitution->id,
        'include_pending' => true,
        'include_hidden' => true,
    ]);

    expect($results)->toHaveCount(0);
});

// --- dates ---

it('filters date_from and date_to inclusively', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    Transaction::factory()->for($account)->create(['name' => 'D24', 'date' => '2026-09-24']);
    $d25 = Transaction::factory()->for($account)->create(['name' => 'D25', 'date' => '2026-09-25']);
    $d26 = Transaction::factory()->for($account)->create(['name' => 'D26', 'date' => '2026-09-26']);
    Transaction::factory()->for($account)->create(['name' => 'D27', 'date' => '2026-09-27']);

    $results = tqsFiltered($user, ['date_from' => '2026-09-25', 'date_to' => '2026-09-26']);

    expect($results->pluck('id')->sort()->values()->all())
        ->toBe(collect([$d25->id, $d26->id])->sort()->values()->all());
});

it('supports an open-ended range with only date_from', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    Transaction::factory()->for($account)->create(['date' => '2026-01-01']);
    $recent = Transaction::factory()->for($account)->create(['date' => '2026-09-01']);

    $results = tqsFiltered($user, ['date_from' => '2026-06-01']);

    expect($results->pluck('id')->all())->toBe([$recent->id]);
});

// --- direction ---

it('outflow returns only positive amounts', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $pos = Transaction::factory()->for($account)->create(['amount' => '25.00']);
    Transaction::factory()->for($account)->create(['amount' => '-25.00']);
    Transaction::factory()->for($account)->create(['amount' => '0.00']);

    $results = tqsFiltered($user, ['direction' => 'outflow']);

    expect($results->pluck('id')->all())->toBe([$pos->id]);
});

it('inflow returns only negative amounts', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    Transaction::factory()->for($account)->create(['amount' => '25.00']);
    $neg = Transaction::factory()->for($account)->create(['amount' => '-25.00']);
    Transaction::factory()->for($account)->create(['amount' => '0.00']);

    $results = tqsFiltered($user, ['direction' => 'inflow']);

    expect($results->pluck('id')->all())->toBe([$neg->id]);
});

it('any returns both directions and zero', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    Transaction::factory()->for($account)->create(['amount' => '25.00']);
    Transaction::factory()->for($account)->create(['amount' => '-25.00']);
    Transaction::factory()->for($account)->create(['amount' => '0.00']);

    expect(tqsFiltered($user, ['direction' => 'any']))->toHaveCount(3);
});

// --- amount magnitude ---

it('amount_min compares against absolute value', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $big = Transaction::factory()->for($account)->create(['amount' => '25.00']);
    $bigNeg = Transaction::factory()->for($account)->create(['amount' => '-25.00']);
    Transaction::factory()->for($account)->create(['amount' => '10.00']);
    Transaction::factory()->for($account)->create(['amount' => '-10.00']);

    $results = tqsFiltered($user, ['amount_min' => 20]);

    expect($results->pluck('id')->sort()->values()->all())
        ->toBe(collect([$big->id, $bigNeg->id])->sort()->values()->all());
});

it('amount_max compares against absolute value', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    Transaction::factory()->for($account)->create(['amount' => '25.00']);
    $small = Transaction::factory()->for($account)->create(['amount' => '10.00']);

    $results = tqsFiltered($user, ['amount_max' => 15]);

    expect($results->pluck('id')->all())->toBe([$small->id]);
});

it('magnitude bounds are inclusive', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $pos = Transaction::factory()->for($account)->create(['amount' => '25.00']);
    $neg = Transaction::factory()->for($account)->create(['amount' => '-25.00']);

    $results = tqsFiltered($user, ['amount_min' => 25, 'amount_max' => 25]);

    expect($results->pluck('id')->sort()->values()->all())
        ->toBe(collect([$pos->id, $neg->id])->sort()->values()->all());
});

it('decimal bound excludes a value one cent below', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    Transaction::factory()->for($account)->create(['amount' => '25.00']);

    $results = tqsFiltered($user, ['amount_min' => '25.01']);

    expect($results)->toHaveCount(0);
});

it('combines direction and magnitude', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    Transaction::factory()->for($account)->create(['amount' => '25.00']);
    $match = Transaction::factory()->for($account)->create(['amount' => '-25.00']);
    Transaction::factory()->for($account)->create(['amount' => '-5.00']);

    $results = tqsFiltered($user, ['direction' => 'inflow', 'amount_min' => 20]);

    expect($results->pluck('id')->all())->toBe([$match->id]);
});

it('returns nothing when min exceeds max', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    Transaction::factory()->for($account)->create(['amount' => '25.00']);

    $results = tqsFiltered($user, ['amount_min' => 30, 'amount_max' => 10]);

    expect($results)->toHaveCount(0);
});

// --- account type & id ---

it('filters by account types', function () {
    $user = User::factory()->create();
    $connection = Connection::factory(['user_id' => $user->id])->create();
    $checking = Account::factory()->ofType(AccountType::Checking)->for($connection)->create();
    $creditCard = Account::factory()->ofType(AccountType::CreditCard)->for($connection)->create();

    Transaction::factory()->for($checking)->create();
    $ccTxn = Transaction::factory()->for($creditCard)->create();

    $results = tqsFiltered($user, ['account_types' => ['credit_card']]);

    expect($results->pluck('id')->all())->toBe([$ccTxn->id]);
});

it('filters by account ids', function () {
    $user = User::factory()->create();
    $connection = Connection::factory(['user_id' => $user->id])->create();
    $account1 = Account::factory()->for($connection)->create();
    $account2 = Account::factory()->for($connection)->create();

    $txn1 = Transaction::factory()->for($account1)->create();
    Transaction::factory()->for($account2)->create();

    $results = tqsFiltered($user, ['account_ids' => [$account1->id]]);

    expect($results->pluck('id')->all())->toBe([$txn1->id]);
});

it('treats empty account_types, account_ids and category_slugs as no-ops', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();
    Transaction::factory()->for($account)->create();

    $withDefaults = tqsFiltered($user);
    $withEmptyArrays = tqsFiltered($user, ['account_types' => [], 'account_ids' => [], 'category_slugs' => []]);

    expect($withEmptyArrays)->toHaveCount($withDefaults->count());
});

// --- institution ---

it('matches institution by uuid', function () {
    $user = User::factory()->create();
    $institution = tqsInstitution('JPMorgan Chase');
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))
        ->create(['institution_id' => $institution->id]);
    $other = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $match = Transaction::factory()->for($account)->create();
    Transaction::factory()->for($other)->create();

    $results = tqsFiltered($user, ['institution' => $institution->id]);

    expect($results->pluck('id')->all())->toBe([$match->id]);
});

it('matches institution by case-insensitive name fragment', function () {
    $user = User::factory()->create();
    $institution = tqsInstitution('JPMorgan Chase');
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))
        ->create(['institution_id' => $institution->id]);

    $match = Transaction::factory()->for($account)->create();

    $results = tqsFiltered($user, ['institution' => 'chase']);

    expect($results->pluck('id')->all())->toBe([$match->id]);
});

it('institution name that matches nothing returns empty', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();
    Transaction::factory()->for($account)->create();

    expect(tqsFiltered($user, ['institution' => 'Nonexistent Bank']))->toHaveCount(0);
});

it('uuid for an unknown institution returns empty and does not fall back to name matching', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();
    Transaction::factory()->for($account)->create();

    $results = tqsFiltered($user, ['institution' => (string) Str::uuid()]);

    expect($results)->toHaveCount(0);
});

// --- merchant ---

it('matches merchant against merchant_name case-insensitively', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $match = Transaction::factory()->for($account)->create(['merchant_name' => 'Target', 'name' => 'POS Target']);
    Transaction::factory()->for($account)->create(['merchant_name' => 'Costco', 'name' => 'POS Costco']);

    $results = tqsFiltered($user, ['merchant' => 'target']);

    expect($results->pluck('id')->all())->toBe([$match->id]);
});

it('matches merchant against name when merchant_name is null', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $match = Transaction::factory()->for($account)->create(['merchant_name' => null, 'name' => 'Duke Energy Payment']);

    $results = tqsFiltered($user, ['merchant' => 'duke energy']);

    expect($results->pluck('id')->all())->toBe([$match->id]);
});

it('escapes percent in merchant input', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $match = Transaction::factory()->for($account)->create(['name' => '100% Juice']);
    Transaction::factory()->for($account)->create(['name' => '1000 Juice']);

    $results = tqsFiltered($user, ['merchant' => '100%']);

    expect($results->pluck('id')->all())->toBe([$match->id]);
});

it('escapes underscore in merchant input', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $match = Transaction::factory()->for($account)->create(['name' => 'A_B']);
    Transaction::factory()->for($account)->create(['name' => 'AXB']);

    $results = tqsFiltered($user, ['merchant' => 'A_B']);

    expect($results->pluck('id')->all())->toBe([$match->id]);
});

it('escapes backslash in merchant input', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $match = Transaction::factory()->for($account)->create(['name' => 'C\\D']);

    $results = tqsFiltered($user, ['merchant' => 'C\\D']);

    expect($results->pluck('id')->all())->toBe([$match->id]);
});

it('merchant OR does not leak past other filters', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();
    $otherAccount = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    Transaction::factory()->for($account)->hidden()->create(['name' => 'Target run']);
    Transaction::factory()->for($otherAccount)->create(['name' => 'Target run']);

    $results = tqsFiltered($user, ['merchant' => 'target', 'account_ids' => [$account->id]]);

    expect($results)->toHaveCount(0);
});

// --- category ---

it('filters by effective category slug using the user override', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $groceries = tqsCategory('groceries');
    $dining = tqsCategory('dining');

    $match = Transaction::factory()->for($account)->create([
        'category_id' => $groceries->id,
        'user_category_id' => $dining->id,
    ]);

    expect(tqsFiltered($user, ['category_slugs' => ['dining']])->pluck('id')->all())->toBe([$match->id]);
    expect(tqsFiltered($user, ['category_slugs' => ['groceries']]))->toHaveCount(0);
});

it('falls back to source category when there is no user override', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $groceries = tqsCategory('groceries');

    $match = Transaction::factory()->for($account)->create(['category_id' => $groceries->id]);

    $results = tqsFiltered($user, ['category_slugs' => ['groceries']]);

    expect($results->pluck('id')->all())->toBe([$match->id]);
});

it('matches any of several category slugs', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $groceries = tqsCategory('groceries');
    $dining = tqsCategory('dining');
    $other = tqsCategory('utilities');

    $g = Transaction::factory()->for($account)->create(['category_id' => $groceries->id]);
    $d = Transaction::factory()->for($account)->create(['category_id' => $dining->id]);
    Transaction::factory()->for($account)->create(['category_id' => $other->id]);

    $results = tqsFiltered($user, ['category_slugs' => ['groceries', 'dining']]);

    expect($results->pluck('id')->sort()->values()->all())
        ->toBe(collect([$g->id, $d->id])->sort()->values()->all());
});

// --- chaining ---

it('returns the same builder instance for chaining', function () {
    $user = User::factory()->create();
    $service = tqsService();
    $query = $service->ownedQuery($user);

    $result = $service->applyFilters($query, TransactionFilters::fromArray([]));

    expect($result)->toBe($query);
});
