<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\CurrentOwner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withEffectiveCategory()
            ->whereHas('connection', fn (Builder $query) => $query->where('user_id', CurrentOwner::id()))
            ->with([
                'account:id,name',
                'category:id,name,slug',
                'userCategory:id,name,slug',
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        // No Create/Edit pages exist for transactions — all editing happens
        // inline in the table (SelectColumn/ToggleColumn) or via the Notes
        // modal action below, both of which define their own schemas.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')
                    ->date()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('merchant_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('account.name')
                    ->label('Account'),
                TextColumn::make('effective_category')
                    ->label('Category')
                    ->badge()
                    // Deliberately NOT the scopeWithEffectiveCategory() query alias:
                    // Livewire's inline-edit refresh only reloads this one row's
                    // model + its eager-loaded relations, it does not re-run the
                    // original joined/aliased list query. Recomputing from the
                    // (fresh, per-row) relations here is what keeps the badge in
                    // sync after the SelectColumn below changes user_category_id.
                    ->getStateUsing(fn (Transaction $record): ?string => ($record->userCategory ?? $record->category)?->name),
                SelectColumn::make('user_category_id')
                    ->label('Category override')
                    ->options(fn (): array => Category::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->selectablePlaceholder(true),
                TextColumn::make('amount')
                    ->money('usd')
                    ->sortable(),
                IconColumn::make('pending')
                    ->boolean(),
                ToggleColumn::make('is_hidden')
                    ->label('Hidden'),
            ])
            ->recordActions([
                self::notesAction(),
            ])
            ->filters([
                SelectFilter::make('account_id')
                    ->label('Account')
                    ->multiple()
                    ->options(fn (): array => Account::query()
                        ->whereHas('connection', fn (Builder $query) => $query->where('user_id', CurrentOwner::id()))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                Filter::make('category')
                    ->schema([
                        Select::make('category_id')
                            ->label('Category')
                            ->options(fn (): array => Category::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['category_id'] ?? null)) {
                            return $query;
                        }

                        // Filters against the real columns, never the
                        // scopeWithEffectiveCategory() alias — Postgres
                        // rejects referencing a SELECT alias in a WHERE clause.
                        return $query->whereRaw(
                            'COALESCE(transactions.user_category_id, transactions.category_id) = ?',
                            [$data['category_id']]
                        );
                    }),
                TernaryFilter::make('pending'),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('date', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('date', '<=', $date));
                    }),
            ])
            ->defaultSort('date', 'desc')
            ->paginationPageOptions([25, 50, 100, 200])
            ->defaultPaginationPageOption(50);
    }

    private static function notesAction(): Action
    {
        return Action::make('notes')
            ->label('Notes')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->fillForm(fn (Transaction $record): array => [
                'user_notes' => $record->user_notes,
            ])
            ->schema([
                Textarea::make('user_notes')
                    ->label('Notes')
                    ->rows(4),
            ])
            ->action(function (Transaction $record, array $data): void {
                $record->update(['user_notes' => $data['user_notes']]);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactions::route('/'),
        ];
    }
}
