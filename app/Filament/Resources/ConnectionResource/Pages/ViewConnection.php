<?php

namespace App\Filament\Resources\ConnectionResource\Pages;

use App\Enums\ConnectionStatus;
use App\Filament\Resources\ConnectionResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewConnection extends ViewRecord
{
    protected static string $resource = ConnectionResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('provider')
                ->badge(),
            TextEntry::make('status')
                ->badge()
                ->color(fn (ConnectionStatus $state): string => match ($state) {
                    ConnectionStatus::Active => 'success',
                    ConnectionStatus::PendingExpiration => 'warning',
                    ConnectionStatus::LoginRequired, ConnectionStatus::Error => 'danger',
                    ConnectionStatus::Revoked => 'gray',
                }),
            TextEntry::make('last_successful_sync_at')
                ->label('Last successful sync')
                ->dateTime()
                ->since(),
            TextEntry::make('last_attempted_sync_at')
                ->label('Last attempted sync')
                ->dateTime()
                ->since(),
            TextEntry::make('status_detail')
                ->label('Status detail')
                ->placeholder('—'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
