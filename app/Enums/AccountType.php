<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

enum AccountType: string
{
    case Checking = 'checking';
    case Savings = 'savings';
    case CreditCard = 'credit_card';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Checking => 'Checking',
            self::Savings => 'Savings',
            self::CreditCard => 'Credit card',
            self::Other => 'Other',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Checking => Heroicon::OutlinedBuildingLibrary,
            self::Savings => Heroicon::OutlinedWallet,
            self::CreditCard => Heroicon::OutlinedCreditCard,
            self::Other => Heroicon::OutlinedArchiveBox,
        };
    }
}
