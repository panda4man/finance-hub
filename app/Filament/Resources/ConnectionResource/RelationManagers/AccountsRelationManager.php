<?php

namespace App\Filament\Resources\ConnectionResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'accounts';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('mask')
                    ->label('Account #')
                    ->formatStateUsing(fn (?string $state): string => $state ? "••{$state}" : '—'),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('subtype'),
                TextColumn::make('current_balance')
                    ->label('Balance')
                    ->money(fn ($record): string => $record->iso_currency_code ?? 'USD'),
                TextColumn::make('available_balance')
                    ->label('Available')
                    ->money(fn ($record): string => $record->iso_currency_code ?? 'USD'),
                TextColumn::make('balances_updated_at')
                    ->label('Balances updated')
                    ->dateTime()
                    ->since(),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
