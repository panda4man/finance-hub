<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

enum AccountType: string
{
    case Checking = 'checking';
    case Savings = 'savings';
    case CreditCard = 'credit_card';
    case Loan = 'loan';
    case Investment = 'investment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Checking => 'Checking',
            self::Savings => 'Savings',
            self::CreditCard => 'Credit card',
            self::Loan => 'Loan',
            self::Investment => 'Investment',
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
            self::Investment => Heroicon::OutlinedChartPie,
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
            self::Checking, self::Savings, self::Investment => 1,
            self::CreditCard, self::Loan => -1,
            self::Other => 0,
        };
    }

    /**
     * Sign this type contributes to a liquid (short-term) net-cash total: Loan and
     * Investment return 0 here while netCashSign() still counts them, so neither a
     * mortgage nor an IRA can distort how much spendable cash is on hand. Written as
     * its own exhaustive match rather than a default arm over netCashSign(), so a
     * future long-term case has to be classified here deliberately.
     */
    public function liquidNetCashSign(): int
    {
        return match ($this) {
            self::Checking, self::Savings => 1,
            self::CreditCard => -1,
            self::Loan, self::Investment, self::Other => 0,
        };
    }
}
