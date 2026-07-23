<?php

namespace App\Filament\Resources\ImportTemplateResource\Pages;

use App\Filament\Resources\ImportTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditImportTemplate extends EditRecord
{
    protected static string $resource = ImportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
