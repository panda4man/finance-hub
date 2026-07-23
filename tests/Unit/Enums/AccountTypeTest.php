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
