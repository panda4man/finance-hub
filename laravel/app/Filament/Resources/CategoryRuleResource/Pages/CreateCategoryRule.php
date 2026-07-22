<?php

namespace App\Filament\Resources\CategoryRuleResource\Pages;

use App\Filament\Resources\CategoryRuleResource;
use App\Models\CategoryRule;
use Filament\Resources\Pages\CreateRecord;

class CreateCategoryRule extends CreateRecord
{
    protected static string $resource = CategoryRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['priority'] = (CategoryRule::query()->max('priority') ?? 0) + 1;

        return $data;
    }
}
