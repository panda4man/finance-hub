<?php

namespace App\Filament\Resources\ConnectionResource\RelationManagers;

use App\Enums\AccountType;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'accounts';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('account_type')
                ->label('Account type')
                ->options(array_combine(
                    array_map(fn (AccountType $case): string => $case->value, AccountType::cases()),
                    array_map(fn (AccountType $case): string => $case->label(), AccountType::cases()),
                ))
                ->native(false),
        ]);
    }

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
                TextColumn::make('account_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?AccountType $state): string => $state?->label() ?? '—'),
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
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
