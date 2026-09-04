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

it('returns correct icon for Investment account type', function () {
    expect(AccountType::Investment->icon())->toBe(Heroicon::OutlinedChartPie);
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

it('returns correct label for Investment account type', function () {
    expect(AccountType::Investment->label())->toBe('Investment');
});

it('exposes investment as the backed value for the Investment account type', function () {
    expect(AccountType::Investment->value)->toBe('investment');
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

it('returns +1 net cash sign for Investment account type', function () {
    expect(AccountType::Investment->netCashSign())->toBe(1);
});

it('returns a defined net cash sign for every case, so a new case fails here first', function () {
    foreach (AccountType::cases() as $case) {
        expect($case->netCashSign())->toBeInt();
    }
});

it('returns +1 liquid net cash sign for Checking account type', function () {
    expect(AccountType::Checking->liquidNetCashSign())->toBe(1);
});

it('returns +1 liquid net cash sign for Savings account type', function () {
    expect(AccountType::Savings->liquidNetCashSign())->toBe(1);
});

it('returns -1 liquid net cash sign for CreditCard account type', function () {
    expect(AccountType::CreditCard->liquidNetCashSign())->toBe(-1);
});

it('returns 0 liquid net cash sign for Loan account type, so a mortgage never lands in liquid cash', function () {
    expect(AccountType::Loan->liquidNetCashSign())->toBe(0);
});

it('returns 0 liquid net cash sign for Other account type', function () {
    expect(AccountType::Other->liquidNetCashSign())->toBe(0);
});

it('returns 0 liquid net cash sign for Investment account type, so an IRA never lands in liquid cash', function () {
    expect(AccountType::Investment->liquidNetCashSign())->toBe(0);
});

it('returns a defined liquid net cash sign for every case, so a new case fails here first', function () {
    foreach (AccountType::cases() as $case) {
        expect($case->liquidNetCashSign())->toBeInt();
    }
});

it('diverges from netCashSign only for Loan and Investment, the two types held out of liquid cash', function () {
    $diverging = array_values(array_filter(
        AccountType::cases(),
        fn (AccountType $case): bool => $case->liquidNetCashSign() !== $case->netCashSign(),
    ));

    expect($diverging)->toEqualCanonicalizing([AccountType::Loan, AccountType::Investment]);
});
