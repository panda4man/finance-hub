<?php

namespace App\Filament\Resources\ImportTemplateResource\Pages;

use App\Filament\Resources\ImportTemplateResource;
use App\Models\ImportTemplate;
use App\Support\Import\CsvSampleAnonymizer;
use App\Support\Import\CsvTemplateSuggester;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use SplFileObject;

class CreateImportTemplate extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    /**
     * Cap on how many data rows are read out of an uploaded sample CSV for
     * idempotency-key analysis (e.g. checking a column for uniqueness) —
     * plenty to tell a real id column from a coincidence, without reading a
     * file that could have years of transactions in it.
     */
    private const SAMPLE_ROW_LIMIT = 25;

    protected static string $resource = ImportTemplateResource::class;

    public function mount(): void
    {
        parent::mount();

        $this->fillFromCloneSource(request()->query('clone_from'));
    }

    /**
     * Prefills the wizard from an existing template (the "Clone" row action
     * on the list page links here with ?clone_from=<id>) so updating just
     * the candidate CSV — or a handful of fields — on an existing template
     * doesn't require rebuilding one from scratch. The Sample CSV step is
     * deliberately left blank: uploading a fresh file there re-runs
     * suggestion/snapshot analysis as normal, and skipping it just keeps
     * these cloned values with no snapshot until a file is uploaded later.
     */
    public function fillFromCloneSource(?string $importTemplateId): void
    {
        if ($importTemplateId === null) {
            return;
        }

        $source = ImportTemplate::query()->find($importTemplateId);

        if ($source === null) {
            return;
        }

        $this->form->fill([
            'name' => "{$source->name} (copy)",
            'institution_id' => $source->institution_id,
            'date_format' => $source->date_format,
            'flip_amount_sign' => $source->flip_amount_sign,
            'column_mapping' => $source->column_mapping,
            'header_signature' => $source->header_signature,
            'dedupe_strategy' => $source->dedupe_strategy->value,
            'dedupe_columns' => $source->dedupe_columns,
        ]);
    }

    protected function getSteps(): array
    {
        return [
            Step::make('Sample CSV')
                ->description('Optional — upload an example export to prefill the fields below')
                ->schema([
                    FileUpload::make('sample_file')
                        ->label('Sample CSV file')
                        ->disk('local')
                        ->directory('csv-template-samples')
                        ->preserveFilenames()
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->helperText('Upload an example export from your bank to prefill the column mapping and idempotency key below. You can skip this and fill everything in manually.'),
                    Hidden::make('sample_snapshot'),
                ])
                ->afterValidation(function (Get $get, Set $set): void {
                    $this->applySuggestionsFromSample($get('sample_file'), $set);
                }),
            Step::make('Details')
                ->schema(ImportTemplateResource::detailsFields()),
            Step::make('Column mapping')
                ->schema(ImportTemplateResource::columnMappingFields()),
            Step::make('Idempotency key')
                ->schema(ImportTemplateResource::idempotencyFields()),
        ];
    }

    /**
     * FileUpload's raw form state is always array-keyed internally, even for
     * a single, non-multiple file — Get/Set read that raw state directly
     * (they don't apply the component's own scalar-casting), so the stored
     * path has to be pulled out of the array here.
     */
    private function applySuggestionsFromSample(mixed $rawSampleFile, Set $set): void
    {
        $storedPath = Arr::first(Arr::wrap($rawSampleFile));

        if (blank($storedPath)) {
            return;
        }

        $file = new SplFileObject(Storage::disk('local')->path($storedPath), 'r');
        $file->setCsvControl(',', '"', '\\');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $header = $file->fgetcsv();
        if ($header === false || $header === null) {
            return;
        }

        $normalizedHeader = array_map(static fn (mixed $cell): string => trim((string) $cell), $header);
        $columnCount = count($normalizedHeader);

        $sampleRows = [];
        while (! $file->eof() && count($sampleRows) < self::SAMPLE_ROW_LIMIT) {
            $fields = $file->fgetcsv();

            if ($fields === false || $fields === null || $fields === [null]) {
                continue;
            }

            $paddedFields = array_pad(array_slice($fields, 0, $columnCount), $columnCount, null);
            $sampleRows[] = array_combine($normalizedHeader, $paddedFields);
        }

        $suggestion = app(CsvTemplateSuggester::class)->analyze($normalizedHeader, $sampleRows);

        $set('header_signature', $suggestion['header_signature']);
        $set('column_mapping', $suggestion['column_mapping']);
        $set('dedupe_strategy', $suggestion['dedupe_strategy']->value);
        $set('dedupe_columns', $suggestion['dedupe_columns']);

        $snapshot = app(CsvSampleAnonymizer::class)->anonymize($normalizedHeader, $sampleRows, $suggestion['column_mapping']);
        $set('sample_snapshot', $snapshot);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $storedPath = $data['sample_file'] ?? null;

        if (! blank($storedPath)) {
            Storage::disk('local')->delete($storedPath);
        }

        unset($data['sample_file']);

        return $data;
    }
}
