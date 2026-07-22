<?php

use App\Models\ImportTemplate;
use App\Support\Import\ImportTemplateMatcher;
use Database\Seeders\ImportTemplateSeeder;

beforeEach(fn () => $this->seed(ImportTemplateSeeder::class));

it('detects the Chase template from an exact header match', function () {
    $matcher = app(ImportTemplateMatcher::class);

    $template = $matcher->detectTemplate(['Details', 'Posting Date', 'Description', 'Amount', 'Type', 'Balance', 'Check or Slip #']);

    expect($template)->not->toBeNull();
    expect($template->name)->toBe('Chase checking');
});

it('tolerates surrounding whitespace in header cells', function () {
    $matcher = app(ImportTemplateMatcher::class);

    $template = $matcher->detectTemplate([' Details ', 'Posting Date', 'Description ', 'Amount', 'Type', 'Balance', 'Check or Slip #']);

    expect($template)->not->toBeNull();
    expect($template->name)->toBe('Chase checking');
});

it('returns null when no template matches the header', function () {
    $matcher = app(ImportTemplateMatcher::class);

    $template = $matcher->detectTemplate(['Some', 'Unknown', 'Bank', 'Format']);

    expect($template)->toBeNull();
});

it('returns null on a near-miss header (wrong order or extra column)', function () {
    $matcher = app(ImportTemplateMatcher::class);

    // Reordered — matcher requires an exact ordered match, unlike the parser's lenient check.
    $template = $matcher->detectTemplate(['Posting Date', 'Details', 'Description', 'Amount', 'Type', 'Balance', 'Check or Slip #']);

    expect($template)->toBeNull();
});

it('creates a new custom template and detects it by its own header signature', function () {
    $template = ImportTemplate::create([
        'institution_id' => null,
        'name' => 'Ally checking',
        'column_mapping' => [
            'date' => 'Date',
            'description' => 'Description',
            'amount' => 'Amount',
        ],
        'date_format' => 'Y-m-d',
        'flip_amount_sign' => false,
        'header_signature' => ['Date', 'Description', 'Amount'],
        'is_seeded' => false,
    ]);

    $matcher = app(ImportTemplateMatcher::class);
    $detected = $matcher->detectTemplate(['Date', 'Description', 'Amount']);

    expect($detected?->id)->toBe($template->id);
});
