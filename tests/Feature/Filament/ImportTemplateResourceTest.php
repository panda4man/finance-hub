<?php

use App\Enums\DedupeStrategy;
use App\Filament\Resources\ImportTemplateResource;
use App\Filament\Resources\ImportTemplateResource\Pages\CreateImportTemplate;
use App\Filament\Resources\ImportTemplateResource\Pages\EditImportTemplate;
use App\Filament\Resources\ImportTemplateResource\Pages\ListImportTemplates;
use App\Models\ImportTemplate;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

// shield:generate produces real Policy classes gated on Spatie permissions
// that plain test users don't hold. These tests exercise resource behavior,
// not the authorization layer, so bypass it here.
beforeEach(fn () => Gate::before(fn () => true));

it('blocks saving an external_id template with no external_id mapping', function () {
    $user = User::factory()->create();
    actingAs($user);

    $test = Livewire::test(CreateImportTemplate::class)
        ->fillForm([
            'name' => 'Invalid external_id template',
            'date_format' => 'Y-m-d',
            'column_mapping' => [
                'date' => 'Date',
                'description' => 'Description',
                'amount' => 'Amount',
            ],
            'header_signature' => ['Date', 'Description', 'Amount'],
            'dedupe_strategy' => DedupeStrategy::ExternalId->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['column_mapping']);

    expect($test->errors()->first('data.column_mapping'))->toContain('external_id');
    expect(ImportTemplate::where('name', 'Invalid external_id template')->exists())->toBeFalse();
});

it('blocks saving a composite template referencing an unmapped role', function () {
    $user = User::factory()->create();
    actingAs($user);

    $test = Livewire::test(CreateImportTemplate::class)
        ->fillForm([
            'name' => 'Invalid composite template',
            'date_format' => 'Y-m-d',
            'column_mapping' => [
                'date' => 'Date',
                'description' => 'Description',
                'amount' => 'Amount',
                // 'type' deliberately unmapped even though it's in dedupe_columns below.
            ],
            'header_signature' => ['Date', 'Description', 'Amount'],
            'dedupe_strategy' => DedupeStrategy::Composite->value,
            'dedupe_columns' => ['date', 'amount', 'description', 'type'],
        ])
        ->call('create')
        ->assertHasFormErrors(['column_mapping']);

    expect($test->errors()->first('data.column_mapping'))->toContain('type');
    expect(ImportTemplate::where('name', 'Invalid composite template')->exists())->toBeFalse();
});

it('blocks saving a template missing a core parsing role (date) even when dedupe_columns excludes it', function () {
    $user = User::factory()->create();
    actingAs($user);

    $test = Livewire::test(CreateImportTemplate::class)
        ->fillForm([
            'name' => 'Missing core role template',
            'date_format' => 'Y-m-d',
            'column_mapping' => [
                'description' => 'Description',
                'amount' => 'Amount',
                // 'date' deliberately unmapped.
            ],
            'header_signature' => ['Description', 'Amount'],
            'dedupe_strategy' => DedupeStrategy::Composite->value,
            'dedupe_columns' => ['amount'],
        ])
        ->call('create')
        ->assertHasFormErrors(['column_mapping']);

    expect($test->errors()->first('data.column_mapping'))->toContain('date');
    expect(ImportTemplate::where('name', 'Missing core role template')->exists())->toBeFalse();
});

it('allows saving a valid template', function () {
    $user = User::factory()->create();
    actingAs($user);

    Livewire::test(CreateImportTemplate::class)
        ->fillForm([
            'name' => 'Valid template',
            'date_format' => 'Y-m-d',
            'column_mapping' => [
                'date' => 'Date',
                'description' => 'Description',
                'amount' => 'Amount',
            ],
            'header_signature' => ['Date', 'Description', 'Amount'],
            'dedupe_strategy' => DedupeStrategy::Composite->value,
            'dedupe_columns' => ['date', 'amount', 'description'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ImportTemplate::where('name', 'Valid template')->exists())->toBeTrue();
});

it('prefills column mapping and idempotency key from an uploaded sample CSV', function () {
    $user = User::factory()->create();
    actingAs($user);

    Storage::fake('local');

    $storedPath = 'csv-template-samples/sample.csv';
    Storage::disk('local')->put($storedPath, <<<'CSV'
        Date,Description,Amount,Confirmation #
        2026-01-01,Coffee,-5.00,A1
        2026-01-02,Lunch,-12.00,A2
        CSV);

    // FileUpload's raw form state is always array-keyed internally, even for
    // a single file — fillForm() writes raw state directly, so it must
    // already be in that shape for the step's validation to accept it.
    //
    // Field-level assertions on the mid-wizard state aren't reliable here:
    // KeyValue/CheckboxList hold their own internal representations while
    // live (e.g. KeyValue is backed by a Repeater-style list of entries,
    // not a plain assoc array) that only collapse back to the plain shape
    // on final dehydration. So this drives the wizard through to an actual
    // saved record and asserts on that instead.
    Livewire::test(CreateImportTemplate::class)
        ->fillForm(['sample_file' => [$storedPath]])
        ->call('callSchemaComponentMethod', 'form.data::wizard', 'nextStep', ['currentStepIndex' => 0])
        ->fillForm([
            'name' => 'Prefilled bank',
            'date_format' => 'Y-m-d',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $template = ImportTemplate::where('name', 'Prefilled bank')->firstOrFail();

    expect($template->header_signature)->toBe(['Date', 'Description', 'Amount', 'Confirmation #']);
    expect($template->column_mapping)->toBe([
        'date' => 'Date',
        'description' => 'Description',
        'amount' => 'Amount',
        'external_id' => 'Confirmation #',
    ]);
    expect($template->dedupe_strategy)->toBe(DedupeStrategy::ExternalId);
    // dedupe_columns is hidden in the form once ExternalId is selected, and
    // hidden fields dehydrate to null — matches how ExternalId templates
    // are created elsewhere (e.g. ImportTransactionsTest's invalid template).
    expect($template->dedupe_columns)->toBeNull();
});

it('persists an anonymized 5-row snapshot of the uploaded sample CSV', function () {
    $user = User::factory()->create();
    actingAs($user);

    Storage::fake('local');

    $storedPath = 'csv-template-samples/sample.csv';
    Storage::disk('local')->put($storedPath, <<<'CSV'
        Date,Description,Amount,Confirmation #
        2026-01-01,Coffee,-5.00,A1
        2026-01-02,Lunch,-12.00,A2
        2026-01-03,Groceries,-45.10,A3
        2026-01-04,Gas,-38.20,A4
        2026-01-05,Rent,-1200.00,A5
        CSV);

    Livewire::test(CreateImportTemplate::class)
        ->fillForm(['sample_file' => [$storedPath]])
        ->call('callSchemaComponentMethod', 'form.data::wizard', 'nextStep', ['currentStepIndex' => 0])
        ->fillForm([
            'name' => 'Snapshot bank',
            'date_format' => 'Y-m-d',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $template = ImportTemplate::where('name', 'Snapshot bank')->firstOrFail();

    expect($template->sample_snapshot)->not->toBeNull();
    expect($template->sample_snapshot['header'])->toBe(['Date', 'Description', 'Amount', 'Confirmation #']);
    // Header + 4 data rows max, even though the sample file has 5 data rows.
    expect($template->sample_snapshot['rows'])->toHaveCount(4);
    // date passes through unmasked; description/amount/external_id are masked.
    expect($template->sample_snapshot['rows'][0])->toBe(['2026-01-01', 'XXXXXX', '-9.99', 'X9']);

    // The uploaded file itself is still discarded, same as before.
    Storage::disk('local')->assertMissing($storedPath);
});

it('shows the anonymized sample snapshot on the edit page when present', function () {
    $user = User::factory()->create();
    actingAs($user);

    $template = ImportTemplate::create([
        'name' => 'With snapshot',
        'column_mapping' => ['date' => 'Date', 'description' => 'Description', 'amount' => 'Amount'],
        'date_format' => 'Y-m-d',
        'dedupe_strategy' => DedupeStrategy::Composite->value,
        'dedupe_columns' => ['date', 'amount', 'description'],
        'header_signature' => ['Date', 'Description', 'Amount'],
        'sample_snapshot' => [
            'header' => ['Date', 'Description', 'Amount'],
            'rows' => [['2026-01-01', 'XXXXXX', '-9.99']],
        ],
    ]);

    Livewire::test(EditImportTemplate::class, ['record' => $template->getRouteKey()])
        ->assertSee('Candidate CSV sample')
        ->assertSee('XXXXXX');
});

it('hides the sample snapshot section when the template has none', function () {
    $user = User::factory()->create();
    actingAs($user);

    $template = ImportTemplate::create([
        'name' => 'No snapshot',
        'column_mapping' => ['date' => 'Date', 'description' => 'Description', 'amount' => 'Amount'],
        'date_format' => 'Y-m-d',
        'dedupe_strategy' => DedupeStrategy::Composite->value,
        'dedupe_columns' => ['date', 'amount', 'description'],
        'header_signature' => ['Date', 'Description', 'Amount'],
    ]);

    Livewire::test(EditImportTemplate::class, ['record' => $template->getRouteKey()])
        ->assertDontSee('Candidate CSV sample');
});

it('leaves existing form values untouched when no sample CSV is uploaded', function () {
    $user = User::factory()->create();
    actingAs($user);

    Livewire::test(CreateImportTemplate::class)
        ->fillForm(['dedupe_strategy' => DedupeStrategy::ExternalId->value])
        ->call('callSchemaComponentMethod', 'form.data::wizard', 'nextStep', ['currentStepIndex' => 0])
        ->assertSet('data.dedupe_strategy', DedupeStrategy::ExternalId->value);
});

it('links the list page clone action to the create wizard with the source template preselected', function () {
    $user = User::factory()->create();
    actingAs($user);

    $source = ImportTemplate::create([
        'name' => 'Source bank',
        'column_mapping' => ['date' => 'Date', 'description' => 'Description', 'amount' => 'Amount'],
        'date_format' => 'Y-m-d',
        'dedupe_strategy' => DedupeStrategy::Composite->value,
        'dedupe_columns' => ['date', 'amount', 'description'],
        'header_signature' => ['Date', 'Description', 'Amount'],
    ]);

    Livewire::test(ListImportTemplates::class)
        ->assertActionExists(TestAction::make('clone')->table($source))
        ->assertActionHasUrl(
            TestAction::make('clone')->table($source),
            ImportTemplateResource::getUrl('create', ['clone_from' => $source->getKey()]),
        );
});

it('prefills the wizard from a source template when cloning, leaving the sample CSV step blank', function () {
    $user = User::factory()->create();
    actingAs($user);

    $source = ImportTemplate::create([
        'name' => 'Source bank',
        'column_mapping' => ['date' => 'Date', 'description' => 'Description', 'amount' => 'Amount'],
        'date_format' => 'Y-m-d',
        'flip_amount_sign' => true,
        'dedupe_strategy' => DedupeStrategy::Composite->value,
        'dedupe_columns' => ['date', 'amount', 'description'],
        'header_signature' => ['Date', 'Description', 'Amount'],
        'sample_snapshot' => ['header' => ['Date'], 'rows' => [['2026-01-01']]],
    ]);

    // KeyValue/TagsInput/CheckboxList hold their own internal
    // representations while live rather than the plain shape stored on the
    // model (same caveat noted on the sample-CSV prefill test above), so
    // this drives the wizard through to an actual saved record for those
    // fields and only asserts mid-form state for plain inputs.
    Livewire::test(CreateImportTemplate::class)
        ->call('fillFromCloneSource', $source->getKey())
        ->assertSet('data.name', 'Source bank (copy)')
        ->assertSet('data.date_format', 'Y-m-d')
        ->assertSet('data.flip_amount_sign', true)
        ->assertSet('data.dedupe_strategy', DedupeStrategy::Composite->value)
        ->assertSet('data.dedupe_columns', $source->dedupe_columns)
        ->assertSet('data.sample_file', [])
        ->assertSet('data.sample_snapshot', null)
        ->call('create')
        ->assertHasNoFormErrors();

    $clone = ImportTemplate::where('name', 'Source bank (copy)')->firstOrFail();
    expect($clone->column_mapping)->toBe($source->column_mapping);
    expect($clone->header_signature)->toBe($source->header_signature);
    expect($clone->dedupe_columns)->toBe($source->dedupe_columns);
    expect($clone->sample_snapshot)->toBeNull();
});
