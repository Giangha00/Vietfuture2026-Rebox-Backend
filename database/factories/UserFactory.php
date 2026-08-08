<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\Validators;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('09########'),
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'user',
            'avatar_url' => '/default-avatar.svg',
            'bio' => '',
            'email_verified' => true,
            'email_verified_at' => now(),
            'fcm_tokens' => [],
            'delivery_address' => Validators::emptyAddress(),
            'pickup_address' => Validators::emptyAddress(),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified' => false,
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
    }
}
