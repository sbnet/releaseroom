<?php

namespace Database\Factories;

use App\Enums\CandidateState;
use App\Enums\IngestionSource;
use App\Models\Project;
use App\Models\PullRequestCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PullRequestCandidate>
 */
class PullRequestCandidateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 9999);

        return [
            'project_id' => Project::factory(),
            'github_id' => fake()->unique()->numberBetween(1, 999999999),
            'number' => $number,
            'title' => fake()->sentence(),
            'body' => fake()->paragraph(),
            'author_login' => fake()->userName(),
            'author_avatar_url' => 'https://avatars.githubusercontent.com/u/'.fake()->numberBetween(1, 99999),
            'labels' => [],
            'base_branch' => 'main',
            'merged_at' => fake()->dateTimeBetween('-6 months'),
            'html_url' => 'https://github.com/acme/platform/pull/'.$number,
            'state' => CandidateState::Pending,
            'curated_at' => null,
            'ingested_via' => IngestionSource::Webhook,
        ];
    }

    /**
     * Attach the candidate to an existing project.
     */
    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $project->id,
        ]);
    }

    /**
     * A candidate the owner has ruled out.
     */
    public function dismissed(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => CandidateState::Dismissed,
            'curated_at' => now(),
        ]);
    }

    /**
     * A candidate the owner has ruled on, and which ingestion may therefore
     * no longer overwrite.
     */
    public function curated(): static
    {
        return $this->state(fn (array $attributes) => [
            'curated_at' => now(),
        ]);
    }
}
