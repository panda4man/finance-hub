<?php

namespace App\Filament\Resources\ConnectionResource\RelationManagers;

use App\Enums\SyncStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SyncRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'syncRuns';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('started_at', 'desc')
            ->columns([
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
                TextColumn::make('added_count')
                    ->label('Added'),
                TextColumn::make('modified_count')
                    ->label('Modified'),
                TextColumn::make('error_message')
                    ->limit(50)
                    ->tooltip(fn (?string $state): ?string => $state),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
