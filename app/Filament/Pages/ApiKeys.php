<?php

namespace App\Filament\Pages;

use App\Actions\ApiTokens\IssueApiTokenAction;
use App\Actions\ApiTokens\RotateApiTokenAction;
use App\Models\User;
use App\Support\CurrentOwner;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;
use UnitEnum;

class ApiKeys extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $title = 'API Keys';

    protected static ?string $slug = 'api-keys';

    protected string $view = 'filament.pages.api-keys';

    /**
     * Ability names match TransactionPolicy's own method names 1:1 — each
     * one is checked both as a Sanctum token ability (routes/api.php) and,
     * via that same Policy, as the owner's Spatie permission
     * (Gate::authorize in TransactionController). Only the two the API
     * actually exposes today; extend this when write endpoints exist.
     *
     * @var array<string, string>
     */
    private const ABILITIES = [
        'viewAny' => 'List transactions',
        'view' => 'View a single transaction',
    ];

    /**
     * Set right after issuing/rotating a key so the blade view can render it
     * once, in a dismissible banner. Sanctum never stores or returns the
     * plaintext again after this request, so this is the only chance to
     * show it — deliberately page state, not a nested action/modal (that
     * chained-action approach turned out to be unreliable to mount/close
     * predictably; see git history for the abandoned attempt).
     */
    public ?string $plainTextToken = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PersonalAccessToken::query()
                ->where('tokenable_type', (new User)->getMorphClass())
                ->where('tokenable_id', CurrentOwner::id()))
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('abilities')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::ABILITIES[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('created_at')
                    ->since(),
                TextColumn::make('last_used_at')
                    ->since()
                    ->placeholder('Never'),
                TextColumn::make('expires_at')
                    ->since()
                    ->placeholder('Never')
                    ->color(fn (?CarbonInterface $state): ?string => $state?->isPast() ? 'danger' : null),
            ])
            ->recordActions([
                $this->rotateAction(),
                $this->revokeAction(),
            ])
            ->headerActions([
                $this->createAction(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No API keys yet')
            ->emptyStateDescription('Create one to access your transactions from outside the app.')
            ->emptyStateIcon(Heroicon::OutlinedKey);
    }

    public function dismissPlainTextToken(): void
    {
        $this->plainTextToken = null;
    }

    protected function createAction(): Action
    {
        return Action::make('create')
            ->label('New API key')
            ->icon(Heroicon::OutlinedPlus)
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                CheckboxList::make('abilities')
                    ->options(self::ABILITIES)
                    ->default(array_keys(self::ABILITIES))
                    ->required()
                    ->columns(1),
                Select::make('expires_in')
                    ->label('Expires')
                    ->options([
                        '30' => '30 days',
                        '90' => '90 days',
                        '365' => '365 days',
                        'never' => 'Never',
                    ])
                    ->default('90')
                    ->required(),
            ])
            ->action(function (array $data): void {
                $expiresAt = $data['expires_in'] === 'never'
                    ? null
                    : now()->addDays((int) $data['expires_in']);

                $newToken = app(IssueApiTokenAction::class)->execute(
                    User::findOrFail(CurrentOwner::id()),
                    $data['name'],
                    array_values($data['abilities']),
                    $expiresAt,
                );

                $this->plainTextToken = $newToken->plainTextToken;
            });
    }

    protected function rotateAction(): Action
    {
        return Action::make('rotate')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalDescription('The current key stops working immediately.')
            ->action(function (PersonalAccessToken $record): void {
                $newToken = app(RotateApiTokenAction::class)->execute($record);

                $this->plainTextToken = $newToken->plainTextToken;
            });
    }

    protected function revokeAction(): Action
    {
        return Action::make('revoke')
            ->color('danger')
            ->icon(Heroicon::OutlinedTrash)
            ->requiresConfirmation()
            ->action(fn (PersonalAccessToken $record) => $record->delete());
    }
}
