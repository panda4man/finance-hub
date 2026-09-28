<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'connection_id' => fn (array $attributes) => Account::query()
                ->whereKey($attributes['account_id'])
                ->value('connection_id'),
            'external_transaction_id' => 'txn-'.Str::random(16),
            'amount' => '42.50',
            'date' => now()->toDateString(),
            'name' => fake()->company(),
            'merchant_name' => null,
            'pending' => false,
            'is_hidden' => false,
            'removed_at' => null,
            'raw_payload' => [],
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'pending' => true,
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_hidden' => true,
        ]);
    }

    public function removed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'removed_at' => now(),
        ]);
    }
}
