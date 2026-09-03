<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

enum AccountType: string
{
    case Checking = 'checking';
    case Savings = 'savings';
    case CreditCard = 'credit_card';
    case Loan = 'loan';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Checking => 'Checking',
            self::Savings => 'Savings',
            self::CreditCard => 'Credit card',
            self::Loan => 'Loan',
            self::Other => 'Other',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Checking => Heroicon::OutlinedBuildingLibrary,
            self::Savings => Heroicon::OutlinedWallet,
            self::CreditCard => Heroicon::OutlinedCreditCard,
            self::Loan => Heroicon::OutlinedBanknotes,
            self::Other => Heroicon::OutlinedArchiveBox,
        };
    }

    /**
     * Sign this type contributes to a net-cash total: +1 counts the balance as an
     * asset, -1 as debt, 0 leaves it out. The sign is decided here rather than read
     * from the balance so a provider that reports credit-card debt as a positive
     * number can't flip the total.
     */
    public function netCashSign(): int
    {
        return match ($this) {
            self::Checking, self::Savings => 1,
            self::CreditCard, self::Loan => -1,
            self::Other => 0,
        };
    }
}
