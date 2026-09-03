<?php

use App\Enums\AccountType;
use App\Enums\ConnectionStatus;
use App\Filament\Widgets\NetCashOverview;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

// shield:generate produces real Policy classes gated on Spatie permissions
// that plain test users don't hold. These tests exercise widget behavior,
// not the authorization layer, so bypass it here.
beforeEach(fn () => Gate::before(fn () => true));

function makeNetCashConnection(User $user): Connection
{
    return Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'status' => ConnectionStatus::Active,
    ]);
}

function makeNetCashAccount(Connection $connection, array $overrides = []): Account
{
    return Account::create(array_merge([
        'connection_id' => $connection->id,
        'external_account_id' => 'net-cash-'.Str::random(8),
        'name' => 'Test Account',
    ], $overrides));
}

it('computes net cash as assets minus debts across checking, savings and credit accounts', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, ['name' => 'Checking', 'account_type' => AccountType::Checking, 'current_balance' => '1000.00']);
    makeNetCashAccount($connection, ['name' => 'Savings', 'account_type' => AccountType::Savings, 'current_balance' => '500.00']);
    makeNetCashAccount($connection, ['name' => 'Credit Card', 'account_type' => AccountType::CreditCard, 'current_balance' => '-200.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('$1,300.00') // net: 1000 + 500 - 200
        ->assertSee('$1,500.00') // assets: 1000 + 500
        ->assertSee('-$200.00'); // debts, rendered negative
});

it('coerces a credit-card balance stored as a positive number into a negative contribution', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, ['name' => 'Credit Card', 'account_type' => AccountType::CreditCard, 'current_balance' => '150.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('-$150.00')
        ->assertSee('-$150.00'); // net cash equals the debt when there are no assets
});

it('coerces a credit-card balance stored as a negative number into the same negative contribution', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, ['name' => 'Credit Card', 'account_type' => AccountType::CreditCard, 'current_balance' => '-150.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('-$150.00');
});

it('counts a loan account toward debts the same way a credit card is counted', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, ['name' => 'Checking', 'account_type' => AccountType::Checking, 'current_balance' => '1000.00']);
    makeNetCashAccount($connection, ['name' => 'Mortgage', 'account_type' => AccountType::Loan, 'current_balance' => '250000.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('-$249,000.00') // net: 1000 - 250000
        ->assertSee('$1,000.00') // assets
        ->assertSee('-$250,000.00'); // debts, rendered negative
});

it('coerces a loan balance stored as a positive number into a negative contribution', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, ['name' => 'Mortgage', 'account_type' => AccountType::Loan, 'current_balance' => '250000.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('-$250,000.00');
});

it('describes the debts tile as credit/loan rather than credit alone', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, ['name' => 'Mortgage', 'account_type' => AccountType::Loan, 'current_balance' => '250000.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('1 credit/loan');
});

it('shows liquid net cash excluding loan debt while net cash still includes it', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, ['name' => 'Checking', 'account_type' => AccountType::Checking, 'current_balance' => '5000.00']);
    makeNetCashAccount($connection, ['name' => 'Credit Card', 'account_type' => AccountType::CreditCard, 'current_balance' => '-800.00']);
    makeNetCashAccount($connection, ['name' => 'Mortgage', 'account_type' => AccountType::Loan, 'current_balance' => '250000.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('$4,200.00') // liquid net cash: 5000 - 800, loan excluded
        ->assertSee('-$245,800.00') // net cash: 5000 - 800 - 250000, loan still included
        ->assertSee('$5,000.00') // assets
        ->assertSee('-$250,800.00'); // debts: 800 + 250000, rendered negative
});

it('reports the excluded loan total in the liquid net cash description', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, ['name' => 'Checking', 'account_type' => AccountType::Checking, 'current_balance' => '5000.00']);
    makeNetCashAccount($connection, ['name' => 'Mortgage', 'account_type' => AccountType::Loan, 'current_balance' => '250000.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('$250,000.00 loan debt excluded');
});

it('reports the subtracted card debt total in the liquid net cash description', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, ['name' => 'Checking', 'account_type' => AccountType::Checking, 'current_balance' => '1000.00']);
    makeNetCashAccount($connection, ['name' => 'Credit Card', 'account_type' => AccountType::CreditCard, 'current_balance' => '-200.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('$200.00 card debt subtracted')
        ->assertDontSee('loan debt excluded');
});

it('describes liquid net cash as same as assets when there is no debt at all', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, ['name' => 'Checking', 'account_type' => AccountType::Checking, 'current_balance' => '1000.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('Same as assets, no card debt');
});

it('shows a negative liquid net cash when card debt exceeds cash on hand', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, ['name' => 'Checking', 'account_type' => AccountType::Checking, 'current_balance' => '100.00']);
    makeNetCashAccount($connection, ['name' => 'Credit Card', 'account_type' => AccountType::CreditCard, 'current_balance' => '-500.00']);
    makeNetCashAccount($connection, ['name' => 'Mortgage', 'account_type' => AccountType::Loan, 'current_balance' => '1000.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('-$400.00') // liquid net cash: 100 - 500
        ->assertSee('-$1,400.00'); // net cash: 100 - 500 - 1000
});

it('excludes another user\'s accounts from every stat', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    $connection = makeNetCashConnection($user);
    $strangerConnection = makeNetCashConnection($stranger);

    makeNetCashAccount($connection, ['name' => 'Mine', 'account_type' => AccountType::Checking, 'current_balance' => '100.00']);
    makeNetCashAccount($strangerConnection, ['name' => 'Theirs', 'account_type' => AccountType::Checking, 'current_balance' => '99999.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('$100.00')
        ->assertDontSee('$99,999.00');
});

it('excludes accounts with a null account_type and reports them as unclassified', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, ['name' => 'Unclassified', 'current_balance' => '500.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('$0.00')
        ->assertSee('1 accounts not classified yet');
});

it('excludes accounts typed Other without counting them as unclassified', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, ['name' => 'Miscellaneous', 'account_type' => AccountType::Other, 'current_balance' => '500.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('$0.00')
        ->assertDontSee('not classified yet');
});

it('treats a null current_balance as zero while still counting the account', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, ['name' => 'No balance yet', 'account_type' => AccountType::Checking]);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('$0.00')
        ->assertSee('1 checking/savings');
});

it('excludes accounts whose include_in_net_cash is false', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, [
        'name' => 'Excluded',
        'account_type' => AccountType::Checking,
        'current_balance' => '1000.00',
        'include_in_net_cash' => false,
    ]);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('$0.00')
        ->assertDontSee('$1,000.00');
});

it('defaults include_in_net_cash to true for newly created accounts', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    $account = makeNetCashAccount($connection, ['account_type' => AccountType::Checking]);

    expect($account->fresh()->include_in_net_cash)->toBeTrue();
});

// The `callAction(data: [...])` test helper's `fillForm()` internals
// (`unsetMissingNumericArrayKeys`) mishandle dynamically-keyed nested state
// (field names like "accounts.{uuid}.account_type") and silently drop the
// submitted value. Driving the modal through mountAction() + set() per field
// exercises the same production code path (Livewire's normal per-field
// `updated` lifecycle) without tripping that test-helper-only bug.
it('persists type and inclusion through the Configure action and updates the total', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    $account = makeNetCashAccount($connection, ['name' => 'Checking', 'current_balance' => '1000.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->mountAction('configure')
        ->set("mountedActions.0.data.accounts.{$account->id}.account_type", AccountType::Checking->value)
        ->set("mountedActions.0.data.accounts.{$account->id}.include", true)
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertDispatched('$refresh');

    expect($account->fresh()->account_type)->toBe(AccountType::Checking);
    expect($account->fresh()->include_in_net_cash)->toBeTrue();

    // The action dispatches '$refresh' rather than re-rendering inline (its
    // own response only carries the modal's schema, per Filament's
    // partiallyRenderActionParentSchema()); a browser reacts to that dispatch
    // with a follow-up request, which a fresh mount here stands in for.
    Livewire::test(NetCashOverview::class)->assertSee('$1,000.00');
});

it('ignores a stranger\'s account id submitted to the Configure action', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    $connection = makeNetCashConnection($user);
    $strangerConnection = makeNetCashConnection($stranger);

    $account = makeNetCashAccount($connection, ['name' => 'Mine', 'account_type' => AccountType::Checking, 'current_balance' => '100.00']);
    $strangerAccount = makeNetCashAccount($strangerConnection, [
        'name' => 'Theirs',
        'account_type' => AccountType::Checking,
        'current_balance' => '5000.00',
    ]);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->mountAction('configure')
        ->set("mountedActions.0.data.accounts.{$account->id}.account_type", AccountType::Savings->value)
        ->set("mountedActions.0.data.accounts.{$account->id}.include", true)
        // A tampered payload naming a stranger's account: the widget's own
        // schema never renders a field for it (ownedAccounts() is
        // owner-scoped), so this merely proves stray state can't leak
        // through to affect an account outside the current user's scope.
        ->set("mountedActions.0.data.accounts.{$strangerAccount->id}.account_type", AccountType::CreditCard->value)
        ->set("mountedActions.0.data.accounts.{$strangerAccount->id}.include", false)
        ->callMountedAction()
        ->assertHasNoErrors();

    expect($account->fresh()->account_type)->toBe(AccountType::Savings);
    expect($strangerAccount->fresh()->account_type)->toBe(AccountType::Checking);
    expect($strangerAccount->fresh()->include_in_net_cash)->toBeTrue();
});

// The test above proves stray state can't leak through Filament's own schema
// dehydration (it never renders a field for a stranger's account id, so the
// value never reaches $data at all). That's real, but it never actually
// exercises the widget's own "loop from owned collection, not submitted
// keys" code — a directly-tampered $data array (e.g. a crafted request that
// bypasses schema resolution some other way) never gets fed to the closure
// in that test. This calls the action's raw closure directly with a
// hand-crafted $data containing a stranger's account id, to prove the app's
// own authorization loop — not just Filament's schema filtering — is what
// keeps a stranger's account safe.
it('the Configure action closure itself ignores a stranger\'s account id, independent of schema filtering', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    $connection = makeNetCashConnection($user);
    $strangerConnection = makeNetCashConnection($stranger);

    $account = makeNetCashAccount($connection, ['name' => 'Mine', 'account_type' => AccountType::Checking, 'current_balance' => '100.00']);
    $strangerAccount = makeNetCashAccount($strangerConnection, [
        'name' => 'Theirs',
        'account_type' => AccountType::Checking,
        'current_balance' => '5000.00',
    ]);

    actingAs($user);

    $widget = new NetCashOverview;
    $closure = $widget->configureAction()->getActionFunction();

    $closure([
        'accounts' => [
            $account->id => ['account_type' => 'savings', 'include' => true],
            $strangerAccount->id => ['account_type' => 'credit_card', 'include' => false],
        ],
    ]);

    expect($account->fresh()->account_type)->toBe(AccountType::Savings);
    expect($strangerAccount->fresh()->account_type)->toBe(AccountType::Checking);
    expect($strangerAccount->fresh()->include_in_net_cash)->toBeTrue();
});

it('reports the most recent balances_updated_at across included accounts', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, [
        'name' => 'Older',
        'account_type' => AccountType::Checking,
        'current_balance' => '100.00',
        'balances_updated_at' => now()->subDays(5),
    ]);
    makeNetCashAccount($connection, [
        'name' => 'Newer',
        'account_type' => AccountType::Savings,
        'current_balance' => '200.00',
        'balances_updated_at' => now()->subHour(),
    ]);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSee('hour ago')
        ->assertDontSee('5 days ago');
});

it('renders zero without error for a user with no accounts', function () {
    $user = User::factory()->create();

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->assertSuccessful()
        ->assertSee('$0.00')
        ->assertSee('No balances synced yet');
});

it('sums exactly without float drift across many two-decimal accounts', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    foreach (range(1, 20) as $i) {
        makeNetCashAccount($connection, [
            'name' => "Dime {$i}",
            'account_type' => AccountType::Checking,
            'current_balance' => '0.10',
        ]);
    }

    foreach (range(1, 20) as $i) {
        makeNetCashAccount($connection, [
            'name' => "TwentyCent {$i}",
            'account_type' => AccountType::Checking,
            'current_balance' => '0.20',
        ]);
    }

    foreach (range(1, 20) as $i) {
        makeNetCashAccount($connection, [
            'name' => "ThirtyCent {$i}",
            'account_type' => AccountType::Checking,
            'current_balance' => '0.30',
        ]);
    }

    actingAs($user);

    // 20 * 0.10 + 20 * 0.20 + 20 * 0.30 = 2.00 + 4.00 + 6.00 = 12.00, exactly.
    // Note: at this scale (60 terms), IEEE-754 double drift after summing
    // these values is on the order of 1e-14 — far too small to survive
    // rounding to 2 decimal places, so this assertion would pass identically
    // under naive float accumulation. The real guarantee that the total is
    // computed in integer cents comes from reading
    // NetCashOverview::aggregateTotals() (no float += anywhere in the fold),
    // not from this assertion. Kept as a regression guard against someone
    // reintroducing a decimal/string accumulation bug that a `SUM()`-style
    // aggregation could produce, but it does not prove absence of float
    // drift by itself.
    Livewire::test(NetCashOverview::class)
        ->assertSee('$12.00');
});

it('does not write a garbage account_type when the Configure action receives an invalid value', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    $account = makeNetCashAccount($connection, ['name' => 'Checking', 'account_type' => AccountType::Checking, 'current_balance' => '100.00']);

    actingAs($user);

    $widget = new NetCashOverview;
    $closure = $widget->configureAction()->getActionFunction();

    // 'crypto_wallet' does not resolve via AccountType::tryFrom() — this
    // simulates a tampered/stale client payload naming a case that no
    // longer exists (or never did).
    $closure([
        'accounts' => [
            $account->id => ['account_type' => 'crypto_wallet', 'include' => true],
        ],
    ]);

    // Skipped entirely, not nulled out: the account keeps its prior
    // classification rather than silently losing it.
    expect($account->fresh()->account_type)->toBe(AccountType::Checking);
});

it('renders the Configure modal without error for a user with no accounts', function () {
    $user = User::factory()->create();

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->mountAction('configure')
        ->assertHasNoErrors()
        ->callMountedAction()
        ->assertHasNoErrors();
});

// mountAction()/callMountedAction() drive the action's server-side state
// directly and don't prove the widget's page markup can ever display a
// modal. Filament renders a mounted action's modal lazily via a
// wire:partial="action-modals" placeholder (synced client-side, so its
// contents never appear in a server-rendered HTML snapshot even when
// everything works) — a widget view missing <x-filament-actions::modals />
// has no such placeholder at all, so the Configure button opens nothing in
// a real browser despite every mountAction-based test passing. Assert the
// placeholder itself exists, since that's what actually distinguishes a
// widget that can render the modal from one that can't.
it('renders the action-modals placeholder so a mounted action has somewhere to display', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    makeNetCashAccount($connection, ['name' => 'Checking', 'account_type' => AccountType::Checking, 'current_balance' => '1000.00']);

    actingAs($user);

    Livewire::test(NetCashOverview::class)
        ->mountAction('configure')
        ->assertSee('wire:partial="action-modals"', escape: false);
});

it('does not N+1 query per account when rendering display names', function () {
    $user = User::factory()->create();
    $connection = makeNetCashConnection($user);

    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-net-cash-n1',
        'name' => 'Net Cash Bank',
    ]);

    foreach (range(1, 10) as $i) {
        makeNetCashAccount($connection, [
            'name' => "Account {$i}",
            'institution_id' => $institution->id,
            'account_type' => AccountType::Checking,
            'current_balance' => '10.00',
        ]);
    }

    actingAs($user);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    Livewire::test(NetCashOverview::class)->assertSuccessful();

    // One query for the owned-accounts collection (with eager-loaded
    // institution) should serve all 10 accounts' display_name accessors.
    // A regression here (e.g. dropping the ->with('institution:id,name'))
    // would show up as roughly +10 queries, one per account.
    expect($queryCount)->toBeLessThan(10);
});
