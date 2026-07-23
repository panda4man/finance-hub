<?php

namespace App\Filament\Resources\ImportTemplateResource\Pages;

use App\Filament\Resources\ImportTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImportTemplates extends ListRecords
{
    protected static string $resource = ImportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
