<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ImportTemplateResource\Pages\CreateImportTemplate;
use App\Filament\Resources\ImportTemplateResource\Pages\EditImportTemplate;
use App\Filament\Resources\ImportTemplateResource\Pages\ListImportTemplates;
use App\Models\ImportTemplate;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ImportTemplateResource extends Resource
{
    protected static ?string $model = ImportTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            Select::make('institution_id')
                ->label('Institution')
                ->relationship('institution', 'name')
                ->searchable()
                ->helperText('Leave blank for a generic/custom template.'),
            TextInput::make('date_format')
                ->required()
                ->helperText('PHP date format, e.g. "m/d/Y" matches 07/22/2026.'),
            Toggle::make('flip_amount_sign')
                ->label('Flip amount sign')
                ->helperText('Enable if the source CSV shows money leaving the account as a negative number.'),
            KeyValue::make('column_mapping')
                ->label('Column mapping')
                ->keyLabel('Role')
                ->valueLabel('CSV column header')
                ->helperText('Roles: date, description, amount are required. type, balance are optional.')
                ->required(),
            TagsInput::make('header_signature')
                ->label('Expected header row')
                ->helperText('The exact column headers, in order, used to auto-detect this template from an uploaded file.')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('institution.name')
                    ->label('Institution')
                    ->placeholder('Generic'),
                IconColumn::make('is_seeded')
                    ->label('Built-in')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportTemplates::route('/'),
            'create' => CreateImportTemplate::route('/create'),
            'edit' => EditImportTemplate::route('/{record}/edit'),
        ];
    }
}
