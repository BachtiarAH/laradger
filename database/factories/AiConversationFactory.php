<?php

namespace Database\Factories;

use App\Models\AiConversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiConversation>
 */
class AiConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
        ];
    }

    public function forUser(User $user, ?Tenant $tenant = null): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->getKey(),
            'tenant_id' => ($tenant ?? $user->tenants()->firstOrFail())->getKey(),
        ]);
    }
}
