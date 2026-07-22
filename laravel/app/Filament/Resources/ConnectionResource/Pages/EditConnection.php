<?php

namespace App\Filament\Resources\ConnectionResource\Pages;

use App\Filament\Resources\ConnectionResource;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;

class EditConnection extends EditRecord
{
    protected static string $resource = ConnectionResource::class;

    public function form(Schema $schema): Schema
    {
        // Read-only summary — no credential is ever shown here, and status
        // is never hand-editable (it only moves in response to real sync
        // outcomes via SyncService).
        return $schema->components([
            Placeholder::make('provider')
                ->content(fn (): ?string => $this->record->provider),
            Placeholder::make('status')
                ->content(fn (): ?string => $this->record->status->name),
            Placeholder::make('last_successful_sync_at')
                ->label('Last successful sync')
                ->content(fn (): ?string => $this->record->last_successful_sync_at?->diffForHumans() ?? 'Never'),
            Placeholder::make('last_attempted_sync_at')
                ->label('Last attempted sync')
                ->content(fn (): ?string => $this->record->last_attempted_sync_at?->diffForHumans() ?? 'Never'),
            Placeholder::make('status_detail')
                ->label('Status detail')
                ->content(fn (): ?string => $this->record->status_detail ?? '—'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    // Nothing on this page is dehydrated, so there's nothing to save —
    // drop the default Save/Cancel form actions entirely.
    protected function getFormActions(): array
    {
        return [];
    }
}
