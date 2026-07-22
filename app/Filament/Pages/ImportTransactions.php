<?php

namespace App\Filament\Pages;

use App\Enums\ImportStatus;
use App\Models\Account;
use App\Services\ImportService;
use App\Support\CurrentOwner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

class ImportTransactions extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $title = 'Import Transactions';

    protected string $view = 'filament.pages.import-transactions';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('create_new_account')
                    ->label('Create a new account')
                    ->live(),
                Select::make('account_id')
                    ->label('Account')
                    ->options(fn (): array => Account::query()
                        ->whereHas('connection', fn (Builder $q) => $q
                            ->where('provider', 'manual')
                            ->where('user_id', CurrentOwner::id()))
                        ->get()
                        ->mapWithKeys(fn (Account $account): array => [
                            $account->id => $account->mask
                                ? "{$account->name} (••{$account->mask})"
                                : $account->name,
                        ])
                        ->all())
                    ->visible(fn (Get $get): bool => ! $get('create_new_account'))
                    ->required(fn (Get $get): bool => ! $get('create_new_account')),
                TextInput::make('new_account_name')
                    ->label('Account name')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => (bool) $get('create_new_account'))
                    ->required(fn (Get $get): bool => (bool) $get('create_new_account')),
                TextInput::make('new_account_mask')
                    ->label('Last 4 digits')
                    ->maxLength(4)
                    ->visible(fn (Get $get): bool => (bool) $get('create_new_account')),
                Select::make('new_account_type')
                    ->label('Account type')
                    ->options([
                        'checking' => 'Checking',
                        'savings' => 'Savings',
                        'credit_card' => 'Credit card',
                        'other' => 'Other',
                    ])
                    ->visible(fn (Get $get): bool => (bool) $get('create_new_account')),
                FileUpload::make('file')
                    ->label('CSV file')
                    ->disk('local')
                    ->directory('csv-imports')
                    ->preserveFilenames()
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                    ->required(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('import')
                ->footer([
                    Actions::make($this->getFormActions())
                        ->key('form-actions'),
                ]),
        ]);
    }

    public function import(): void
    {
        $data = $this->form->getState();

        $importService = app(ImportService::class);

        $accountId = $data['create_new_account']
            ? $importService->createManualAccount(
                CurrentOwner::id(),
                $data['new_account_name'],
                $data['new_account_mask'] ?: null,
                $data['new_account_type'] ?? null,
            )->id
            : $data['account_id'];

        $storedPath = $data['file'];
        $absolutePath = Storage::disk('local')->path($storedPath);
        $fileName = basename($storedPath);

        try {
            $run = $importService->importFile($accountId, $absolutePath, $fileName);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Import failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title("Imported: {$run->added_count} added, {$run->duplicate_count} duplicate, {$run->failed_count} failed")
            ->color(match ($run->status) {
                ImportStatus::Success => 'success',
                ImportStatus::Partial => 'warning',
                default => 'danger',
            })
            ->send();

        $this->form->fill();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('import')
                ->label('Import')
                ->submit('import'),
        ];
    }
}
