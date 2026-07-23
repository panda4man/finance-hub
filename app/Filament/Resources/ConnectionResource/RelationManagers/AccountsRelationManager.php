<?php

namespace App\Filament\Resources\ConnectionResource\RelationManagers;

use App\Enums\AccountType;
use App\Models\Account;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('institution:id,name,logo_base64'))
            ->columns([
                ImageColumn::make('institution.logo_base64')
                    ->label('Bank')
                    ->circular()
                    ->getStateUsing(fn (Account $record): ?string => $record->institution?->logo_base64
                        ? 'data:image/png;base64,'.$record->institution->logo_base64
                        : null)
                    ->tooltip(fn (Account $record): ?string => $record->institution?->name),
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
