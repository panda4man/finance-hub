<?php

namespace App\Filament\Pages;

use App\Enums\ImportStatus;
use App\Filament\Resources\ImportTemplateResource;
use App\Models\Account;
use App\Models\ImportTemplate;
use App\Models\Institution;
use App\Services\ImportService;
use App\Support\CurrentOwner;
use App\Support\Import\ImportTemplateMatcher;
use BackedEnum;
use Filament\Actions\Action as HeaderAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use SplFileObject;
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
                Wizard::make([
                    Step::make('Account')
                        ->schema([
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
                            Select::make('new_account_institution_id')
                                ->label('Institution')
                                ->options(fn (): array => Institution::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->visible(fn (Get $get): bool => (bool) $get('create_new_account')),
                        ]),
                    Step::make('File')
                        ->schema([
                            FileUpload::make('file')
                                ->label('CSV file')
                                ->disk('local')
                                ->directory('csv-imports')
                                ->preserveFilenames()
                                ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                                ->required(),
                            Hidden::make('detected_template_id'),
                        ])
                        ->afterValidation(function (Get $get, Set $set): void {
                            $set('detected_template_id', $this->detectTemplateId($get('file')));
                        }),
                    Step::make('Template')
                        ->schema([
                            Select::make('template_id')
                                ->label('Import template')
                                ->options(fn (): array => ImportTemplate::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->required(),
                            Actions::make([
                                HeaderAction::make('createTemplate')
                                    ->label('Don\'t see your bank? Create a new import template')
                                    ->link()
                                    ->url(fn (): string => ImportTemplateResource::getUrl('create'))
                                    ->openUrlInNewTab(),
                            ]),
                        ])
                        ->visible(fn (Get $get): bool => blank($get('detected_template_id'))),
                    Step::make('Confirm')
                        ->schema([
                            Placeholder::make('resolved_template')
                                ->label('Import template')
                                ->content(fn (Get $get): string => ImportTemplate::find($get('detected_template_id') ?? $get('template_id'))?->name ?? '—'),
                        ]),
                ])
                    ->submitAction($this->getImportAction())
                    ->alpineSubmitHandler('$wire.import()')
                    ->contained(false),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
        ]);
    }

    protected function getImportAction(): HeaderAction
    {
        return HeaderAction::make('import')
            ->label('Import')
            ->submit('import');
    }

    /**
     * FileUpload's raw form state is always array-keyed internally, even for
     * a single, non-multiple file — Get/Set read that raw state directly
     * (they don't apply the component's own scalar-casting), so the stored
     * path has to be pulled out of the array here.
     */
    private function detectTemplateId(mixed $rawFile): ?string
    {
        $storedPath = Arr::first(Arr::wrap($rawFile));

        if (blank($storedPath)) {
            return null;
        }

        $absolutePath = Storage::disk('local')->path($storedPath);
        $file = new SplFileObject($absolutePath, 'r');
        $file->setCsvControl(',', '"', '\\');
        $header = $file->fgetcsv();

        if ($header === false || $header === null) {
            return null;
        }

        return app(ImportTemplateMatcher::class)->detectTemplate($header)?->id;
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
                $data['new_account_institution_id'] ?? null,
            )->id
            : $data['account_id'];

        $templateId = $data['detected_template_id'] ?? $data['template_id'] ?? null;

        $storedPath = $data['file'];
        $absolutePath = Storage::disk('local')->path($storedPath);
        $fileName = basename($storedPath);

        try {
            $run = $importService->importFile($accountId, $templateId, $absolutePath, $fileName);
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
}
