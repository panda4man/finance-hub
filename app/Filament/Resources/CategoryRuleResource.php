<?php

namespace App\Filament\Resources;

use App\Enums\AmountSign;
use App\Enums\MatchField;
use App\Enums\MatchType;
use App\Enums\RuleSource;
use App\Filament\Resources\CategoryRuleResource\Pages\CreateCategoryRule;
use App\Filament\Resources\CategoryRuleResource\Pages\EditCategoryRule;
use App\Filament\Resources\CategoryRuleResource\Pages\ListCategoryRules;
use App\Models\CategoryRule;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class CategoryRuleResource extends Resource
{
    protected static ?string $model = CategoryRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Taxonomy';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('pattern')
                ->required(),
            Select::make('match_field')
                ->options(MatchField::class)
                ->default(MatchField::Name->value)
                ->required(),
            Select::make('match_type')
                ->options(MatchType::class)
                ->default(MatchType::Substring->value)
                ->required(),
            Select::make('amount_sign')
                ->options(AmountSign::class)
                ->default(AmountSign::Any->value)
                ->required(),
            Select::make('source')
                ->options(RuleSource::class)
                ->default(RuleSource::User->value)
                ->required(),
            Select::make('category_id')
                ->relationship('category', 'name')
                ->searchable()
                ->required(),
            Toggle::make('is_active')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('priority')
            ->defaultSort('priority', 'asc')
            ->columns([
                TextColumn::make('pattern'),
                TextColumn::make('match_field')
                    ->badge(),
                TextColumn::make('match_type')
                    ->badge(),
                TextColumn::make('amount_sign')
                    ->badge(),
                TextColumn::make('category.name')
                    ->label('Category'),
                TextColumn::make('source')
                    ->badge(),
                ToggleColumn::make('is_active'),
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
            'index' => ListCategoryRules::route('/'),
            'create' => CreateCategoryRule::route('/create'),
            'edit' => EditCategoryRule::route('/{record}/edit'),
        ];
    }
}
