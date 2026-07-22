<?php

namespace App\Filament\Widgets;

use App\Enums\SyncStatus;
use App\Models\SyncRun;
use App\Support\CurrentOwner;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class SyncStatusOverview extends TableWidget
{
    public function table(Table $table): Table
    {
        return $table
            ->heading('Latest sync per connection')
            ->query($this->latestSyncRunPerConnectionQuery())
            ->columns([
                TextColumn::make('connection.provider')
                    ->label('Connection'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (SyncStatus $state): string => match ($state) {
                        SyncStatus::Success => 'success',
                        SyncStatus::Running => 'info',
                        SyncStatus::Partial => 'warning',
                        SyncStatus::Failed => 'danger',
                    }),
                TextColumn::make('started_at')
                    ->since(),
                TextColumn::make('added_count')
                    ->label('Added'),
                TextColumn::make('error_message')
                    ->limit(50),
            ])
            ->paginated(false);
    }

    private function latestSyncRunPerConnectionQuery(): Builder
    {
        // Postgres DISTINCT ON (connection_id), ordered started_at desc, keeps
        // exactly one — the most recent — row per connection.
        return SyncRun::query()
            ->selectRaw('DISTINCT ON (sync_runs.connection_id) sync_runs.*')
            ->whereNotNull('connection_id')
            ->whereHas('connection', fn (Builder $query) => $query->where('user_id', CurrentOwner::id()))
            ->with('connection')
            ->orderBy('connection_id')
            ->orderByDesc('started_at');
    }
}
