<?php

namespace App\Filament\Resources\ConnectionResource\Pages;

use App\Filament\Resources\ConnectionResource;
use App\Services\ConnectionService;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class CreateConnection extends CreateRecord
{
    protected static string $resource = ConnectionResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('setup_token')
                ->label('SimpleFin setup token')
                ->required()
                ->rows(4)
                ->dehydrated(false),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        // setup_token is dehydrated(false) — it's never a Connection column,
        // so it never touches Model::create(). That also means it is stripped
        // from $data by the time handleRecordCreation() runs (dehydration
        // happens inside form->getState(), before this hook fires). Read the
        // raw, pre-dehydration field value instead.
        $setupToken = (string) ($this->form->getRawState()['setup_token'] ?? '');

        return app(ConnectionService::class)->createOrRefreshFromSetupToken($setupToken);
    }
}
