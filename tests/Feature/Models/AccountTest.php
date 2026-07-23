<?php

use App\Models\Account;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;

it('prefixes the display name with the institution name when one is set', function () {
    $user = User::factory()->create();
    $connection = Connection::create(['user_id' => $user->id, 'provider' => 'simplefin', 'status' => 'active']);
    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-1',
        'name' => 'Chase',
    ]);

    $account = Account::create([
        'connection_id' => $connection->id,
        'institution_id' => $institution->id,
        'external_account_id' => 'simplefin:acct-1',
        'name' => 'Checking',
    ]);

    expect($account->display_name)->toBe('Chase Checking');
});

it('falls back to the plain account name when there is no institution', function () {
    $user = User::factory()->create();
    $connection = Connection::create(['user_id' => $user->id, 'provider' => 'manual', 'status' => 'active']);

    $account = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'manual:acct-1',
        'name' => 'Checking',
    ]);

    expect($account->display_name)->toBe('Checking');
});
