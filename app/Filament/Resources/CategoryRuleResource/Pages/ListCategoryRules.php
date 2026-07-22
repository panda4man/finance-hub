<?php

namespace App\Filament\Resources\CategoryRuleResource\Pages;

use App\Filament\Resources\CategoryRuleResource;
use App\Jobs\RecategorizeAllJob;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCategoryRules extends ListRecords
{
    protected static string $resource = CategoryRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recategorizeAll')
                ->label('Recategorize All')
                ->requiresConfirmation()
                ->action(function (): void {
                    RecategorizeAllJob::dispatch();

                    Notification::make()
                        ->title('Recategorization queued')
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
