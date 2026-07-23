<?php

use App\Filament\Resources\InstitutionResource;
use App\Filament\Resources\InstitutionResource\Pages\EditInstitution;
use App\Filament\Resources\InstitutionResource\Pages\ListInstitutions;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

// shield:generate produces real Policy classes gated on Spatie permissions
// that plain test users don't hold. These tests exercise resource behavior,
// not the authorization layer, so bypass it here.
beforeEach(fn () => Gate::before(fn () => true));

it('does not allow creating institutions', function () {
    expect(InstitutionResource::canCreate())->toBeFalse();
});

it('does not allow deleting institutions', function () {
    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-1',
        'name' => 'Test Bank',
    ]);

    expect(InstitutionResource::canDelete($institution))->toBeFalse();
});

it('displays institutions in a list with name, url, and logo columns', function () {
    $user = User::factory()->create();

    Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-1',
        'name' => 'Chase Bank',
        'url' => 'https://chase.com',
    ]);

    actingAs($user);

    Livewire::test(ListInstitutions::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([Institution::first()]);
});

it('displays the Fetch logo action in the table', function () {
    $user = User::factory()->create();

    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-1',
        'name' => 'Chase Bank',
        'url' => 'https://chase.com',
    ]);

    actingAs($user);

    Livewire::test(ListInstitutions::class)
        ->assertTableActionVisible('fetchLogo', $institution);
});

it('successfully fetches and stores a logo via the fetchLogo action', function () {
    $user = User::factory()->create();

    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-1',
        'name' => 'Chase Bank',
        'url' => 'https://chase.com',
    ]);

    $fakePngData = 'fake-png-binary-data';

    Http::fake([
        '*google.com*' => Http::response($fakePngData, 200),
    ]);

    actingAs($user);

    Livewire::test(ListInstitutions::class)
        ->callTableAction('fetchLogo', $institution)
        ->assertHasNoActionErrors();

    $institution->refresh();

    // Verify the logo was stored (could be from fake or from real google)
    expect($institution->logo_base64)->not->toBeNull();
});

it('does not attempt HTTP call if institution has no URL', function () {
    $user = User::factory()->create();

    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-1',
        'name' => 'Unknown Bank',
        // No URL
    ]);

    Http::fake();

    actingAs($user);

    Livewire::test(ListInstitutions::class)
        ->callTableAction('fetchLogo', $institution);

    Http::assertNothingSent();
});

it('does not attempt HTTP call if institution has empty string URL', function () {
    $user = User::factory()->create();

    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-1',
        'name' => 'Unknown Bank',
        'url' => '',
    ]);

    Http::fake();

    actingAs($user);

    Livewire::test(ListInstitutions::class)
        ->callTableAction('fetchLogo', $institution);

    Http::assertNothingSent();
});

it('leaves logo_base64 unchanged when HTTP fetch fails', function () {
    $user = User::factory()->create();

    $originalLogo = base64_encode('original-logo');

    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-1',
        'name' => 'Chase Bank',
        'url' => 'https://chase.com',
        'logo_base64' => $originalLogo,
    ]);

    Http::fake([
        '*google.com*' => Http::response('Error', 500),
    ]);

    actingAs($user);

    Livewire::test(ListInstitutions::class)
        ->callTableAction('fetchLogo', $institution)
        ->assertHasNoActionErrors();

    $institution->refresh();

    // Logo should not have been updated due to the 500 error
    expect($institution->logo_base64)->toBe($originalLogo);
});

it('parses domain from URL correctly and makes favicon request', function () {
    $user = User::factory()->create();

    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-1',
        'name' => 'Bank',
        'url' => 'https://subdomain.bankname.com:8080/some/path?query=param',
    ]);

    Http::fake([
        '*google.com*' => Http::response('png-data', 200),
    ]);

    actingAs($user);

    Livewire::test(ListInstitutions::class)
        ->callTableAction('fetchLogo', $institution)
        ->assertHasNoActionErrors();

    // Verify a request was made to Google's favicon service
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'google.com/s2/favicons');
    });
});

it('allows editing institution name and URL via EditInstitution page', function () {
    $user = User::factory()->create();

    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-1',
        'name' => 'Old Bank Name',
        'url' => 'https://oldbank.com',
    ]);

    actingAs($user);

    Livewire::test(EditInstitution::class, ['record' => $institution->getRouteKey()])
        ->fillForm([
            'name' => 'New Bank Name',
            'url' => 'https://newbank.com',
        ])
        ->call('save');

    $institution->refresh();

    expect($institution->name)->toBe('New Bank Name');
    expect($institution->url)->toBe('https://newbank.com');
});

it('mutateFormDataBeforeSave converts file upload to base64 and deletes the temp upload', function () {
    Storage::fake('local');

    $user = User::factory()->create();

    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-1',
        'name' => 'Bank',
        'url' => 'https://bank.com',
    ]);

    // A real, tiny, valid 1x1 PNG (binary, not just an ASCII string) — proves
    // the base64 round-trip works on genuine image bytes.
    $logoBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    $storedPath = 'institution-logo-uploads/'.Str::random(20).'.png';
    Storage::disk('local')->put($storedPath, $logoBytes);

    actingAs($user);

    // FileUpload's raw form state is array-keyed even for a single file (see
    // EditInstitution::mutateFormDataBeforeSave's doc comment) — fillForm()
    // writes raw state directly, so it must already be in that shape here,
    // matching the same convention CreateImportTemplateTest uses for its
    // `sample_file` FileUpload field.
    Livewire::test(EditInstitution::class, ['record' => $institution->getRouteKey()])
        ->fillForm([
            'name' => $institution->name,
            'url' => $institution->url,
            'logo_upload' => [$storedPath],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $institution->refresh();

    expect($institution->logo_base64)->not->toBeNull();
    expect(base64_decode($institution->logo_base64, true))->toBe($logoBytes);
    Storage::disk('local')->assertMissing($storedPath);
});

it('does not modify logo_base64 if logo_upload is not provided', function () {
    $user = User::factory()->create();

    $originalLogo = base64_encode('original-logo');

    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-1',
        'name' => 'Bank',
        'url' => 'https://bank.com',
        'logo_base64' => $originalLogo,
    ]);

    actingAs($user);

    Livewire::test(EditInstitution::class, ['record' => $institution->getRouteKey()])
        ->fillForm([
            'name' => 'Updated Bank',
            'url' => $institution->url,
            // No logo_upload provided
        ])
        ->call('save');

    $institution->refresh();

    expect($institution->logo_base64)->toBe($originalLogo);
});

it('displays image column with data URI for base64 logos', function () {
    $user = User::factory()->create();

    $logoData = base64_encode('png-image-data');

    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-1',
        'name' => 'Chase Bank',
        'logo_base64' => $logoData,
    ]);

    actingAs($user);

    // Drives the real ImageColumn::getStateUsing() closure defined in
    // InstitutionResource::table(), not a re-derivation of the same
    // expression in the test itself.
    Livewire::test(ListInstitutions::class)
        ->assertTableColumnStateSet('logo_base64', 'data:image/png;base64,'.$logoData, $institution);
});

it('displays a null image column state when the institution has no logo_base64', function () {
    $user = User::factory()->create();

    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-1',
        'name' => 'No Logo Bank',
    ]);

    actingAs($user);

    Livewire::test(ListInstitutions::class)
        ->assertTableColumnStateSet('logo_base64', null, $institution);
});
