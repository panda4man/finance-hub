<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Connection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => Connection::factory(),
            'institution_id' => null,
            'external_account_id' => 'acc-'.Str::random(12),
            'name' => fake()->word(),
            'account_type' => AccountType::Checking,
        ];
    }

    public function ofType(AccountType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_type' => $type,
        ]);
    }
}
