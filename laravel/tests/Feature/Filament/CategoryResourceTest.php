<?php

use App\Filament\Resources\CategoryResource;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

// shield:generate produces real Policy classes gated on Spatie permissions
// that plain test users don't hold. These tests exercise resource behavior,
// not the authorization layer, so bypass it here.
beforeEach(fn () => Gate::before(fn () => true));

it('locks name/slug/kind/source fields for a source_provided category but leaves parent_id and is_active editable', function () {
    $user = User::factory()->create();
    $category = Category::create([
        'slug' => 'food-and-drink-groceries',
        'name' => 'Groceries',
        'kind' => 'source_provided',
        'source_primary' => 'FOOD_AND_DRINK',
        'source_detailed' => 'FOOD_AND_DRINK_GROCERIES',
        'is_active' => true,
    ]);

    actingAs($user);

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->assertFormFieldIsDisabled('name')
        ->assertFormFieldIsDisabled('slug')
        ->assertFormFieldIsDisabled('kind')
        ->assertFormFieldIsDisabled('source_primary')
        ->assertFormFieldIsDisabled('source_detailed')
        ->assertFormFieldIsEnabled('parent_id')
        ->assertFormFieldIsEnabled('is_active');
});

it('leaves every field editable for a custom category', function () {
    $user = User::factory()->create();
    $category = Category::create([
        'slug' => 'my-custom-category',
        'name' => 'My custom category',
        'kind' => 'custom',
        'is_active' => true,
    ]);

    actingAs($user);

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->assertFormFieldIsEnabled('name')
        ->assertFormFieldIsEnabled('slug')
        ->assertFormFieldIsEnabled('kind');
});

it('cannot delete a source_provided category but can delete a custom one', function () {
    $sourceProvided = Category::create([
        'slug' => 'source-provided-cat',
        'name' => 'Source provided',
        'kind' => 'source_provided',
        'is_active' => true,
    ]);
    $custom = Category::create([
        'slug' => 'custom-cat',
        'name' => 'Custom',
        'kind' => 'custom',
        'is_active' => true,
    ]);

    expect(CategoryResource::canDelete($sourceProvided))->toBeFalse();
    expect(CategoryResource::canDelete($custom))->toBeTrue();
});
