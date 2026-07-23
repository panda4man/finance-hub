<?php

namespace App\Filament\Resources;

use App\Enums\DedupeStrategy;
use App\Enums\ImportColumnRole;
use App\Filament\Resources\ImportTemplateResource\Pages\CreateImportTemplate;
use App\Filament\Resources\ImportTemplateResource\Pages\EditImportTemplate;
use App\Filament\Resources\ImportTemplateResource\Pages\ListImportTemplates;
use App\Models\ImportTemplate;
use App\Support\Import\DedupeKeyValidator;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
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
                ->helperText('Roles: date, description, amount are required. type, balance are optional. Map external_id too if the source file has a real unique transaction id and you plan to use it as the idempotency key below.')
                ->required()
                ->rules([
                    fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $columnMapping = $value ?? [];
                        $missing = DedupeKeyValidator::missingCoreRoles($columnMapping);

                        $rawStrategy = $get('dedupe_strategy');
                        $strategy = $rawStrategy instanceof DedupeStrategy
                            ? $rawStrategy
                            : DedupeStrategy::tryFrom((string) $rawStrategy);

                        if ($strategy !== null) {
                            $missing = [
                                ...$missing,
                                ...DedupeKeyValidator::missingDedupeRoles($strategy, $get('dedupe_columns'), $columnMapping),
                            ];
                        }

                        $missing = array_values(array_unique($missing));

                        if ($missing === []) {
                            return;
                        }

                        $names = implode(', ', $missing);
                        $fail("Map a CSV column for every required/idempotency-key role. Missing: {$names}.");
                    },
                ]),
            TagsInput::make('header_signature')
                ->label('Expected header row')
                ->helperText('The exact column headers, in order, used to auto-detect this template from an uploaded file.')
                ->required(),
            Select::make('dedupe_strategy')
                ->label('Idempotency key')
                ->options(DedupeStrategy::class)
                ->default(DedupeStrategy::Composite->value)
                ->live()
                ->required(),
            CheckboxList::make('dedupe_columns')
                ->label('Composite key fields')
                ->options([
                    ImportColumnRole::Date->value => 'Date',
                    ImportColumnRole::Amount->value => 'Amount',
                    ImportColumnRole::Description->value => 'Description',
                    ImportColumnRole::Type->value => 'Type',
                    ImportColumnRole::Balance->value => 'Balance',
                ])
                ->default([
                    ImportColumnRole::Date->value,
                    ImportColumnRole::Amount->value,
                    ImportColumnRole::Description->value,
                ])
                ->helperText('⚠️ Default (date, amount, description) matches how every existing template behaves. Changing this changes what counts as "the same transaction" — on a template that already has imports, it can cause already-imported rows to duplicate or stop being recognized as updates. Only override if you know what you\'re doing.')
                ->visible(fn (Get $get): bool => $get('dedupe_strategy') === DedupeStrategy::Composite->value)
                ->required(fn (Get $get): bool => $get('dedupe_strategy') === DedupeStrategy::Composite->value),
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
