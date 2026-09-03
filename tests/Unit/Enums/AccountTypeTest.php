<?php

use App\Enums\AccountType;
use Filament\Support\Icons\Heroicon;

it('returns correct icon for Checking account type', function () {
    expect(AccountType::Checking->icon())->toBe(Heroicon::OutlinedBuildingLibrary);
});

it('returns correct icon for Savings account type', function () {
    expect(AccountType::Savings->icon())->toBe(Heroicon::OutlinedWallet);
});

it('returns correct icon for CreditCard account type', function () {
    expect(AccountType::CreditCard->icon())->toBe(Heroicon::OutlinedCreditCard);
});

it('returns correct icon for Other account type', function () {
    expect(AccountType::Other->icon())->toBe(Heroicon::OutlinedArchiveBox);
});

it('returns correct icon for Loan account type', function () {
    expect(AccountType::Loan->icon())->toBe(Heroicon::OutlinedBanknotes);
});

it('returns correct label for Checking account type', function () {
    expect(AccountType::Checking->label())->toBe('Checking');
});

it('returns correct label for Savings account type', function () {
    expect(AccountType::Savings->label())->toBe('Savings');
});

it('returns correct label for CreditCard account type', function () {
    expect(AccountType::CreditCard->label())->toBe('Credit card');
});

it('returns correct label for Other account type', function () {
    expect(AccountType::Other->label())->toBe('Other');
});

it('returns correct label for Loan account type', function () {
    expect(AccountType::Loan->label())->toBe('Loan');
});

it('exposes loan as the backed value for the Loan account type', function () {
    expect(AccountType::Loan->value)->toBe('loan');
});

it('returns +1 net cash sign for Checking account type', function () {
    expect(AccountType::Checking->netCashSign())->toBe(1);
});

it('returns +1 net cash sign for Savings account type', function () {
    expect(AccountType::Savings->netCashSign())->toBe(1);
});

it('returns -1 net cash sign for CreditCard account type', function () {
    expect(AccountType::CreditCard->netCashSign())->toBe(-1);
});

it('returns 0 net cash sign for Other account type', function () {
    expect(AccountType::Other->netCashSign())->toBe(0);
});

it('returns -1 net cash sign for Loan account type', function () {
    expect(AccountType::Loan->netCashSign())->toBe(-1);
});

it('returns a defined net cash sign for every case, so a new case fails here first', function () {
    foreach (AccountType::cases() as $case) {
        expect($case->netCashSign())->toBeInt();
    }
});
