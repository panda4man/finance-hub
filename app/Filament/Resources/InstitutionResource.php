<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InstitutionResource\Pages\EditInstitution;
use App\Filament\Resources\InstitutionResource\Pages\ListInstitutions;
use App\Models\Institution;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use UnitEnum;

class InstitutionResource extends Resource
{
    protected static ?string $model = Institution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required(),
            TextInput::make('url')
                ->url(),
            FileUpload::make('logo_upload')
                ->label('Logo')
                ->dehydrated()
                ->disk('local')
                ->directory('institution-logo-uploads')
                ->image()
                ->helperText('Optional — overrides the auto-fetched logo below.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('logo_base64')
                    ->label('Logo')
                    ->circular()
                    ->getStateUsing(fn (Institution $record): ?string => $record->logo_base64
                        ? 'data:image/png;base64,'.$record->logo_base64
                        : null),
                TextColumn::make('name'),
                TextColumn::make('url')
                    ->url(fn (Institution $record): ?string => $record->url, shouldOpenInNewTab: true),
            ])
            ->recordActions([
                self::fetchLogoAction(),
                EditAction::make(),
            ]);
    }

    /**
     * Google's favicon endpoint needs no API key and works for any domain,
     * so it's the default logo source — manual upload (the `logo_upload`
     * field above) is there for banks where the favicon is too low-res or
     * generic to be worth keeping.
     */
    private static function fetchLogoAction(): Action
    {
        return Action::make('fetchLogo')
            ->label('Fetch logo')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(function (Institution $record): void {
                $host = filled($record->url) ? parse_url($record->url, PHP_URL_HOST) : null;

                if (! is_string($host) || $host === '') {
                    Notification::make()
                        ->title('No URL on file for this institution')
                        ->warning()
                        ->send();

                    return;
                }

                $response = Http::timeout(10)->get('https://www.google.com/s2/favicons', [
                    'sz' => 128,
                    'domain' => $host,
                ]);

                if ($response->failed()) {
                    Notification::make()
                        ->title('Logo fetch failed')
                        ->body("Could not fetch a logo for {$host}.")
                        ->danger()
                        ->send();

                    return;
                }

                $record->update(['logo_base64' => base64_encode($response->body())]);

                Notification::make()
                    ->title('Logo fetched')
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstitutions::route('/'),
            'edit' => EditInstitution::route('/{record}/edit'),
        ];
    }
}
