<?php

namespace App\Filament\Resources;

use App\Enums\CategoryKind;
use App\Filament\Resources\CategoryResource\Pages\CreateCategory;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Models\Category;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Taxonomy';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('rules');
    }

    private static function isLocked(?Category $record): bool
    {
        return $record?->kind === CategoryKind::SourceProvided;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, Get $get, ?string $state, ?Category $record): void {
                    // Only auto-derive the slug while creating, and only if
                    // the slug hasn't already been hand-typed.
                    if ($record !== null || filled($get('slug'))) {
                        return;
                    }

                    $set('slug', Str::slug($state ?? ''));
                })
                ->disabled(fn (?Category $record): bool => self::isLocked($record))
                ->dehydrated(fn (?Category $record): bool => ! self::isLocked($record)),
            TextInput::make('slug')
                ->required()
                ->unique(ignoreRecord: true)
                ->disabled(fn (?Category $record): bool => self::isLocked($record))
                ->dehydrated(fn (?Category $record): bool => ! self::isLocked($record)),
            Select::make('parent_id')
                ->relationship('parent', 'name')
                ->searchable()
                ->nullable(),
            Select::make('kind')
                ->options(CategoryKind::class)
                ->required()
                ->disabled(fn (?Category $record): bool => self::isLocked($record))
                ->dehydrated(fn (?Category $record): bool => ! self::isLocked($record)),
            TextInput::make('source_primary')
                ->disabled(fn (?Category $record): bool => self::isLocked($record))
                ->dehydrated(fn (?Category $record): bool => ! self::isLocked($record)),
            TextInput::make('source_detailed')
                ->disabled(fn (?Category $record): bool => self::isLocked($record))
                ->dehydrated(fn (?Category $record): bool => ! self::isLocked($record)),
            Toggle::make('is_active')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('kind')
                    ->badge(),
                TextColumn::make('parent.name')
                    ->label('Parent'),
                TextColumn::make('rules_count')
                    ->label('Rules'),
                ToggleColumn::make('is_active'),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->options(CategoryKind::class),
                TernaryFilter::make('is_active'),
            ]);
    }

    public static function canDelete(Model $record): bool
    {
        /** @var Category $record */
        return $record->kind !== CategoryKind::SourceProvided;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
