<?php

use App\Enums\DedupeStrategy;
use App\Filament\Resources\ImportTemplateResource\Pages\CreateImportTemplate;
use App\Models\ImportTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
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
