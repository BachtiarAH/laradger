<?php

namespace Database\Factories;

use App\Models\AiDraftRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiDraftRequest>
 */
class AiDraftRequestFactory extends Factory
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
            'prompt' => fake()->sentence(6),
            'status' => AiDraftRequest::STATUS_QUEUED,
            'drafts_count' => 0,
            'queued_at' => now(),
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => AiDraftRequest::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function completed(int $drafts = 1): static
    {
        return $this->state(fn (): array => [
            'status' => AiDraftRequest::STATUS_COMPLETED,
            'drafts_count' => $drafts,
            'completed_at' => now(),
        ]);
    }

    public function failed(string $message = 'The AI provider could not be reached.'): static
    {
        return $this->state(fn (): array => [
            'status' => AiDraftRequest::STATUS_FAILED,
            'error' => $message,
            'completed_at' => now(),
        ]);
    }
}
