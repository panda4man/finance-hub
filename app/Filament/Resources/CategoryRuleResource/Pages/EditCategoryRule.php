<?php

namespace App\Filament\Resources\CategoryRuleResource\Pages;

use App\Filament\Resources\CategoryRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCategoryRule extends EditRecord
{
    protected static string $resource = CategoryRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
