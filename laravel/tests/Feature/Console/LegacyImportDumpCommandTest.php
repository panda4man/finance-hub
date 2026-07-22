<?php

use App\Enums\ConnectionStatus;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\Transaction;
use App\Models\User;

function legacyDumpFixturePath(): string
{
    return base_path('tests/Fixtures/legacy-dump-fixture.sql');
}

it('imports only the target user\'s items/institutions/accounts/transactions', function () {
    User::factory()->create();

    $this->artisan('legacy:import-dump', ['path' => legacyDumpFixturePath()])
        ->expectsOutputToContain('Parsed: 1 item(s), 1 institution(s), 1 account(s), 2 transaction(s)')
        ->expectsOutputToContain('Imported: 1 institution(s), 1 connection(s), 1 account(s), 2 transaction(s)')
        ->assertExitCode(0);

    expect(Institution::count())->toBe(1);
    $institution = Institution::first();
    expect($institution->provider)->toBe('plaid_archive');
    expect($institution->external_org_id)->toBe('ins_chase');
    expect($institution->name)->toBe('Chase Bank');

    expect(Connection::count())->toBe(1);
    $connection = Connection::first();
    expect($connection->provider)->toBe('plaid_archive');
    expect($connection->status)->toBe(ConnectionStatus::Revoked);
    expect($connection->credential_encrypted)->toBeNull();
    expect($connection->status_detail)->toBe('Imported from legacy dump (plaid_item_id=plaid-item-abc)');

    expect(Account::count())->toBe(1);
    $account = Account::first();
    expect($account->external_account_id)->toBe('plaid-acc-checking');
    expect($account->name)->toBe('Checking Account');
    expect($account->official_name)->toBe('Chase Total Checking');
    expect($account->mask)->toBe('1234');
    expect($account->type)->toBe('depository');
    expect($account->subtype)->toBe('checking');
    expect($account->connection_id)->toBe($connection->id);
    expect($account->institution_id)->toBe($institution->id);

    expect(Transaction::count())->toBe(2);

    $escaped = Transaction::where('external_transaction_id', 'txn-abc-1')->first();
    expect($escaped)->not->toBeNull();
    expect($escaped->name)->toBe("Trader Joe's");
    expect($escaped->amount)->toBe('-45.67');
    expect($escaped->pending)->toBeFalse();
    expect($escaped->date->toDateString())->toBe('2019-05-01');
    expect($escaped->category_id)->toBeNull();
    expect($escaped->user_category_id)->toBeNull();

    $withPayload = Transaction::where('external_transaction_id', 'txn-abc-2')->first();
    expect($withPayload->raw_payload['legacyCategoryId'])->toBe('5');
    expect($withPayload->raw_payload['plaidCategory'])->toBe(['Payroll']);
    expect($withPayload->raw_payload['location'])->toBe(['address' => '123 Main St']);
    expect($withPayload->raw_payload['paymentMeta'])->toBe(['reference_number' => '1234']);

    // The other user's item/institution/account/transaction must never appear.
    expect(Institution::where('external_org_id', 'ins_other')->exists())->toBeFalse();
    expect(Account::where('external_account_id', 'plaid-acc-other')->exists())->toBeFalse();
    expect(Transaction::where('external_transaction_id', 'txn-other-1')->exists())->toBeFalse();
});

it('is idempotent: running it again does not duplicate or fail', function () {
    User::factory()->create();

    $this->artisan('legacy:import-dump', ['path' => legacyDumpFixturePath()])->assertExitCode(0);

    expect(Institution::count())->toBe(1);
    expect(Connection::count())->toBe(1);
    expect(Account::count())->toBe(1);
    expect(Transaction::count())->toBe(2);

    $this->artisan('legacy:import-dump', ['path' => legacyDumpFixturePath()])
        ->assertExitCode(0);

    expect(Institution::count())->toBe(1);
    expect(Connection::count())->toBe(1);
    expect(Account::count())->toBe(1);
    expect(Transaction::count())->toBe(2);
});

it('fails cleanly when the path does not exist', function () {
    User::factory()->create();

    $this->artisan('legacy:import-dump', ['path' => '/nonexistent/path.sql'])
        ->assertExitCode(1);
});
