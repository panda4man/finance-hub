<?php

namespace App\Filament\Resources\ImportRunResource\Pages;

use App\Filament\Resources\ImportRunResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewImportRun extends ViewRecord
{
    protected static string $resource = ImportRunResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('account.name')
                ->label('Account'),
            TextEntry::make('file_name')
                ->label('File'),
            TextEntry::make('status')
                ->badge(),
            TextEntry::make('started_at')
                ->dateTime(),
            TextEntry::make('finished_at')
                ->dateTime(),
            TextEntry::make('row_count'),
            TextEntry::make('added_count'),
            TextEntry::make('duplicate_count'),
            TextEntry::make('failed_count'),
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
