<?php

namespace Database\Factories;

use App\Enums\ConnectionFailure;
use App\Enums\ConnectionStatus;
use App\Enums\WebhookStatus;
use App\Models\Project;
use App\Models\RepositoryConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RepositoryConnection>
 */
class RepositoryConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = 'github_pat_'.fake()->regexify('[A-Za-z0-9]{22}');

        return [
            'project_id' => Project::factory(),
            // The owner trails the project: the two must agree for the
            // per-owner uniqueness index to mean anything.
            'user_id' => fn (array $attributes): int => Project::query()
                ->whereKey($attributes['project_id'])
                ->firstOrFail()
                ->user_id,
            'github_id' => fake()->unique()->numberBetween(1, 999999999),
            'owner' => fake()->userName(),
            'name' => fake()->slug(2),
            'is_private' => false,
            'default_branch' => 'main',
            'token' => $token,
            'token_last_four' => substr($token, -4),
            'token_expires_at' => null,
            'status' => ConnectionStatus::Connected,
            'last_error_code' => null,
            'last_checked_at' => now(),
            'webhook_token' => Str::random(64),
            'webhook_secret' => Str::random(64),
            'webhook_id' => null,
            'webhook_status' => WebhookStatus::ManualSetupRequired,
            'webhook_last_delivery_at' => null,
            'last_synced_at' => null,
        ];
    }

    /**
     * A connection whose hook we created and which GitHub is delivering to.
     */
    public function withActiveWebhook(): static
    {
        return $this->state(fn (array $attributes) => [
            'webhook_id' => fake()->unique()->numberBetween(1, 999999999),
            'webhook_status' => WebhookStatus::Active,
        ]);
    }

    /**
     * Pin the signing secret, so a test can sign a payload with it.
     */
    public function withWebhookSecret(string $secret): static
    {
        return $this->state(fn (array $attributes) => [
            'webhook_secret' => $secret,
        ]);
    }

    /**
     * Attach the connection to an existing project, owner included.
     */
    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $project->id,
            'user_id' => $project->user_id,
        ]);
    }

    /**
     * Indicate that the last verification failed.
     */
    public function failed(ConnectionFailure $failure = ConnectionFailure::InvalidToken): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConnectionStatus::Failed,
            'last_error_code' => $failure,
        ]);
    }
}
