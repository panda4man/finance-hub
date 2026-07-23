<?php

use App\Filament\Pages\ImportTransactions;
use App\Filament\Resources\ImportTemplateResource;
use App\Models\ImportTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Import\ImportTemplateMatcher;
use Database\Seeders\ImportTemplateSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Gate::before(fn () => true);
    $this->seed(ImportTemplateSeeder::class);
    $this->actingAs(User::factory()->create());
});

it('mounts the import wizard via livewire without error', function () {
    Livewire::test(ImportTransactions::class)->assertSuccessful();
});

it('mounts the import template list page via livewire without error', function () {
    Livewire::test(ImportTemplateResource\Pages\ListImportTemplates::class)->assertSuccessful();
});

it('mounts the import template create page via livewire without error', function () {
    Livewire::test(ImportTemplateResource\Pages\CreateImportTemplate::class)->assertSuccessful();
});

it('completes the golden path: new account + auto-detected Chase template + import', function () {
    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,COFFEE,-10.50,Purchase,1000.00,
CSV;
    Storage::disk('local')->put('csv-imports/golden-path.csv', $csv);

    $detectedTemplateId = app(ImportTemplateMatcher::class)
        ->detectTemplate(['Details', 'Posting Date', 'Description', 'Amount', 'Type', 'Balance', 'Check or Slip #'])
        ?->id;

    expect($detectedTemplateId)->toBe(ImportTemplate::where('name', 'Chase checking')->value('id'));

    $instance = new ImportTransactions;
    $instance->mount();
    $instance->form->fill([
        'create_new_account' => true,
        'new_account_name' => 'Golden Path Checking',
        'file' => 'csv-imports/golden-path.csv',
        'detected_template_id' => $detectedTemplateId,
    ]);
    $instance->import();

    expect(Transaction::count())->toBe(1);
    expect(Transaction::first()->name)->toBe('COFFEE');
    expect((float) Transaction::first()->amount)->toBe(10.50);
});
