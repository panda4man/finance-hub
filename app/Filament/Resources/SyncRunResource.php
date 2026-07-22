<?php

namespace App\Filament\Resources;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Filament\Resources\SyncRunResource\Pages\ListSyncRuns;
use App\Filament\Resources\SyncRunResource\Pages\ViewSyncRun;
use App\Models\Connection;
use App\Models\SyncRun;
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

class SyncRunResource extends Resource
{
    protected static ?string $model = SyncRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('connection', fn (Builder $query) => $query->where('user_id', CurrentOwner::id()))
            ->with('connection');
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
                TextColumn::make('connection.provider')
                    ->label('Connection'),
                TextColumn::make('trigger')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (SyncStatus $state): string => match ($state) {
                        SyncStatus::Success => 'success',
                        SyncStatus::Running => 'info',
                        SyncStatus::Partial => 'warning',
                        SyncStatus::Failed => 'danger',
                    }),
                TextColumn::make('started_at')
                    ->dateTime(),
                TextColumn::make('finished_at')
                    ->dateTime(),
                TextColumn::make('pages_fetched'),
                TextColumn::make('added_count'),
                TextColumn::make('modified_count'),
                TextColumn::make('removed_count'),
                TextColumn::make('error_code'),
                TextColumn::make('error_message')
                    ->limit(50)
                    ->tooltip(fn (?string $state): ?string => $state),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(SyncStatus::class),
                SelectFilter::make('trigger')
                    ->options(SyncTrigger::class),
                SelectFilter::make('connection_id')
                    ->label('Connection')
                    ->options(fn (): array => Connection::query()
                        ->where('user_id', CurrentOwner::id())
                        ->get()
                        ->mapWithKeys(fn (Connection $connection): array => [
                            $connection->id => sprintf('%s (%s)', $connection->provider, substr($connection->id, 0, 8)),
                        ])
                        ->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSyncRuns::route('/'),
            'view' => ViewSyncRun::route('/{record}'),
        ];
    }
}
