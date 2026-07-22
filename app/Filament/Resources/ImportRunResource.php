<?php

namespace App\Filament\Resources;

use App\Enums\ImportStatus;
use App\Filament\Resources\ImportRunResource\Pages\ListImportRuns;
use App\Filament\Resources\ImportRunResource\Pages\ViewImportRun;
use App\Models\Account;
use App\Models\ImportRun;
use App\Support\CurrentOwner;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ImportRunResource extends Resource
{
    protected static ?string $model = ImportRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('connection', fn (Builder $query) => $query->where('user_id', CurrentOwner::id()))
            ->with(['connection', 'account']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('account.name')
                    ->label('Account'),
                TextColumn::make('file_name')
                    ->label('File'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ImportStatus $state): string => match ($state) {
                        ImportStatus::Success => 'success',
                        ImportStatus::Running => 'info',
                        ImportStatus::Partial => 'warning',
                        ImportStatus::Failed => 'danger',
                    }),
                TextColumn::make('started_at')
                    ->dateTime(),
                TextColumn::make('finished_at')
                    ->dateTime(),
                TextColumn::make('row_count')
                    ->label('Rows'),
                TextColumn::make('added_count')
                    ->label('Added'),
                TextColumn::make('duplicate_count')
                    ->label('Duplicates'),
                TextColumn::make('failed_count')
                    ->label('Failed'),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(50)
                    ->tooltip(fn (?string $state): ?string => $state),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ImportStatus::class),
                SelectFilter::make('account_id')
                    ->label('Account')
                    ->options(fn (): array => Account::query()
                        ->whereHas('connection', fn (Builder $q) => $q->where('user_id', CurrentOwner::id()))
                        ->pluck('name', 'id')
                        ->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportRuns::route('/'),
            'view' => ViewImportRun::route('/{record}'),
        ];
    }
}
