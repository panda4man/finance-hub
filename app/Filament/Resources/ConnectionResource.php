<?php

namespace App\Filament\Resources;

use App\Enums\ConnectionStatus;
use App\Enums\SyncTrigger;
use App\Filament\Resources\ConnectionResource\Pages\CreateConnection;
use App\Filament\Resources\ConnectionResource\Pages\EditConnection;
use App\Filament\Resources\ConnectionResource\Pages\ListConnections;
use App\Filament\Resources\ConnectionResource\Pages\ViewConnection;
use App\Filament\Resources\ConnectionResource\RelationManagers\AccountsRelationManager;
use App\Filament\Resources\ConnectionResource\RelationManagers\ImportRunsRelationManager;
use App\Filament\Resources\ConnectionResource\RelationManagers\SyncRunsRelationManager;
use App\Jobs\BackfillConnectionJob;
use App\Jobs\SyncConnectionJob;
use App\Models\Connection;
use App\Support\CurrentOwner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ConnectionResource extends Resource
{
    protected static ?string $model = Connection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', CurrentOwner::id())
            ->withCount('accounts');
    }

    public static function form(Schema $schema): Schema
    {
        // Populated per-page: CreateConnection uses a bespoke setup-token
        // field, EditConnection shows a read-only status summary. Neither
        // reuses a shared resource-level form.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ConnectionStatus $state): string => match ($state) {
                        ConnectionStatus::Active => 'success',
                        ConnectionStatus::PendingExpiration => 'warning',
                        ConnectionStatus::LoginRequired, ConnectionStatus::Error => 'danger',
                        ConnectionStatus::Revoked => 'gray',
                    }),
                TextColumn::make('accounts_count')
                    ->label('Accounts'),
                TextColumn::make('last_successful_sync_at')
                    ->label('Last successful sync')
                    ->dateTime()
                    ->since(),
                TextColumn::make('last_attempted_sync_at')
                    ->label('Last attempted sync')
                    ->dateTime()
                    ->since(),
            ])
            ->recordActions([
                self::syncAction(),
                self::backfillAction(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function canSyncOrBackfill(Connection $record): bool
    {
        return $record->provider === 'simplefin'
            && filled($record->credential_encrypted)
            && ! in_array($record->status, [ConnectionStatus::Revoked, ConnectionStatus::LoginRequired], true);
    }

    private static function syncAction(): Action
    {
        return Action::make('sync')
            ->label('Sync now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->visible(fn (Connection $record): bool => self::canSyncOrBackfill($record))
            ->action(function (Connection $record): void {
                SyncConnectionJob::dispatch($record->id, SyncTrigger::Manual);

                Notification::make()
                    ->title('Sync queued')
                    ->success()
                    ->send();
            });
    }

    private static function backfillAction(): Action
    {
        return Action::make('backfill')
            ->label('Backfill')
            ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
            ->requiresConfirmation()
            ->visible(fn (Connection $record): bool => self::canSyncOrBackfill($record))
            ->action(function (Connection $record): void {
                BackfillConnectionJob::dispatch($record->id);

                Notification::make()
                    ->title('Backfill queued')
                    ->success()
                    ->send();
            });
    }

    public static function getRelations(): array
    {
        return [
            AccountsRelationManager::class,
            SyncRunsRelationManager::class,
            ImportRunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConnections::route('/'),
            'create' => CreateConnection::route('/create'),
            'view' => ViewConnection::route('/{record}'),
            'edit' => EditConnection::route('/{record}/edit'),
        ];
    }
}
