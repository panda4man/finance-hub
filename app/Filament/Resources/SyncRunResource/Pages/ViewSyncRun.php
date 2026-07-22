<?php

namespace App\Filament\Resources\SyncRunResource\Pages;

use App\Filament\Resources\SyncRunResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewSyncRun extends ViewRecord
{
    protected static string $resource = SyncRunResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('connection.provider')
                ->label('Connection'),
            TextEntry::make('trigger')
                ->badge(),
            TextEntry::make('status')
                ->badge(),
            TextEntry::make('started_at')
                ->dateTime(),
            TextEntry::make('finished_at')
                ->dateTime(),
            TextEntry::make('pages_fetched'),
            TextEntry::make('added_count'),
            TextEntry::make('modified_count'),
            TextEntry::make('removed_count'),
            TextEntry::make('accounts_upserted'),
            TextEntry::make('error_code'),
            TextEntry::make('error_message')
                ->label('Error message')
                ->columnSpanFull(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
