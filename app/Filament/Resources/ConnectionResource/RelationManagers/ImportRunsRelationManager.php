<?php

namespace App\Filament\Resources\ConnectionResource\RelationManagers;

use App\Enums\ImportStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ImportRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'importRuns';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('started_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('account.institution'))
            ->columns([
                TextColumn::make('account.display_name')
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
                TextColumn::make('added_count')
                    ->label('Added'),
                TextColumn::make('duplicate_count')
                    ->label('Duplicates'),
                TextColumn::make('failed_count')
                    ->label('Failed'),
                TextColumn::make('error_message')
                    ->limit(50)
                    ->tooltip(fn (?string $state): ?string => $state),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
