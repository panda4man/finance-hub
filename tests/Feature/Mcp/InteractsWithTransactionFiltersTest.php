<?php

use App\Mcp\Concerns\InteractsWithTransactionFilters;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Transactions\TransactionFilters;
use Illuminate\Contracts\JsonSchema\JsonSchema;

function itfHarness(): object
{
    return new class
    {
        use InteractsWithTransactionFilters;

        public function schemaFor(JsonSchema $schema, array $except = []): array
        {
            return $this->transactionFilterSchema($schema, $except);
        }

        public function rulesFor(array $except = []): array
        {
            return $this->transactionFilterRules($except);
        }

        public function filtersFrom(array $validated): TransactionFilters
        {
            return $this->transactionFiltersFrom($validated);
        }

        public function applied(TransactionFilters $filters): array
        {
            return $this->appliedFilters($filters);
        }

        public function present(Transaction $transaction): array
        {
            return $this->presentTransaction($transaction);
        }

        public function moneyPublic(string|int|float|null $value): string
        {
            return $this->money($value);
        }
    };
}

it('presentTransaction adds direction outflow for positive, inflow for negative, zero for 0.00', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();

    $out = Transaction::factory()->for($account)->create(['amount' => '25.00']);
    $in = Transaction::factory()->for($account)->create(['amount' => '-25.00']);
    $zero = Transaction::factory()->for($account)->create(['amount' => '0.00']);

    $harness = itfHarness();

    expect($harness->present($out)['direction'])->toBe('outflow');
    expect($harness->present($in)['direction'])->toBe('inflow');
    expect($harness->present($zero)['direction'])->toBe('zero');
});

it('presentTransaction matches TransactionResource fields plus direction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();
    $transaction = Transaction::factory()->for($account)->create();

    $result = itfHarness()->present($transaction);

    expect(array_keys($result))->toEqualCanonicalizing([
        'id', 'date', 'authorized_date', 'datetime', 'amount', 'iso_currency_code',
        'name', 'merchant_name', 'pending', 'is_hidden', 'payment_channel', 'user_notes',
        'account', 'category', 'source_category', 'created_at', 'direction',
    ]);
});

it('transactionFiltersFrom maps categories to categorySlugs and defaults include flags to false', function () {
    $filters = itfHarness()->filtersFrom(['categories' => ['groceries', 'dining']]);

    expect($filters->categorySlugs)->toBe(['groceries', 'dining']);
    expect($filters->includePending)->toBeFalse();
    expect($filters->includeHidden)->toBeFalse();
});

it('appliedFilters echoes enum values and Y-m-d dates', function () {
    $filters = itfHarness()->filtersFrom([
        'date_from' => '2026-01-01',
        'direction' => 'outflow',
        'account_types' => ['checking'],
    ]);

    $applied = itfHarness()->applied($filters);

    expect($applied['date_from'])->toBe('2026-01-01');
    expect($applied['direction'])->toBe('outflow');
    expect($applied['account_types'])->toBe(['checking']);
});

it('transactionFilterRules omits an excepted key and its wildcard', function () {
    $rules = itfHarness()->rulesFor(['merchant']);

    expect($rules)->not->toHaveKey('merchant');
    expect($rules)->toHaveKey('institution');
});

it('money formats null as zero and rounds to two decimals', function () {
    $harness = itfHarness();

    expect($harness->moneyPublic(null))->toBe('0.00');
    expect($harness->moneyPublic('25.005'))->toBe('25.01');
    expect($harness->moneyPublic(10))->toBe('10.00');
});
