<?php

use App\Enums\DedupeStrategy;
use App\Filament\Resources\ImportTemplateResource\Pages\CreateImportTemplate;
use App\Models\ImportTemplate;
use App\Models\User;
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

it('leaves existing form values untouched when no sample CSV is uploaded', function () {
    $user = User::factory()->create();
    actingAs($user);

    Livewire::test(CreateImportTemplate::class)
        ->fillForm(['dedupe_strategy' => DedupeStrategy::ExternalId->value])
        ->call('callSchemaComponentMethod', 'form.data::wizard', 'nextStep', ['currentStepIndex' => 0])
        ->assertSet('data.dedupe_strategy', DedupeStrategy::ExternalId->value);
});
