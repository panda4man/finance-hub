<?php

namespace App\Filament\Resources\InstitutionResource\Pages;

use App\Filament\Resources\InstitutionResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class EditInstitution extends EditRecord
{
    protected static string $resource = InstitutionResource::class;

    /**
     * `logo_upload` is a virtual, non-model field (see
     * InstitutionResource::form()) — its only purpose is to let the base64
     * `logo_base64` column be overwritten via a normal file picker instead of
     * hand-encoding an upload. FileUpload's raw state is always array-keyed
     * internally even for a single file, hence Arr::wrap()/Arr::first().
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $storedPath = Arr::first(Arr::wrap($data['logo_upload'] ?? null));

        if (filled($storedPath)) {
            $data['logo_base64'] = base64_encode(Storage::disk('local')->get($storedPath));
            Storage::disk('local')->delete($storedPath);
        }

        unset($data['logo_upload']);

        return $data;
    }
}
