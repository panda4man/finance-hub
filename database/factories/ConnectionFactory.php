<?php

namespace Database\Factories;

use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'simplefin',
            'credential_encrypted' => 'https://user:pass@bridge.example.com/simplefin',
            'status' => ConnectionStatus::Active,
        ];
    }
}
