<?php

use App\Filament\Resources\CategoryRuleResource\Pages\CreateCategoryRule;
use App\Filament\Resources\CategoryRuleResource\Pages\ListCategoryRules;
use App\Jobs\RecategorizeAllJob;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

// shield:generate produces real Policy classes gated on Spatie permissions
// that plain test users don't hold. These tests exercise resource behavior,
// not the authorization layer, so bypass it here.
beforeEach(fn () => Gate::before(fn () => true));

it('dispatches RecategorizeAllJob when the Recategorize All header action is run', function () {
    $user = User::factory()->create();
    Queue::fake();

    actingAs($user);

    Livewire::test(ListCategoryRules::class)
        ->callAction('recategorizeAll')
        ->assertHasNoErrors();

    Queue::assertPushed(RecategorizeAllJob::class);
});

it('assigns the next priority automatically when creating a rule', function () {
    $user = User::factory()->create();
    $category = Category::create(['slug' => 'dining', 'name' => 'Dining', 'kind' => 'custom', 'is_active' => true]);

    CategoryRule::create([
        'pattern' => 'existing',
        'match_field' => 'name',
        'match_type' => 'substring',
        'amount_sign' => 'any',
        'category_id' => $category->id,
        'priority' => 5,
        'source' => 'default',
        'is_active' => true,
    ]);

    actingAs($user);

    Livewire::test(CreateCategoryRule::class)
        ->fillForm([
            'pattern' => 'new pattern',
            'match_field' => 'name',
            'match_type' => 'substring',
            'amount_sign' => 'any',
            'source' => 'user',
            'category_id' => $category->id,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = CategoryRule::where('pattern', 'new pattern')->firstOrFail();
    expect($created->priority)->toBe(6);
});
