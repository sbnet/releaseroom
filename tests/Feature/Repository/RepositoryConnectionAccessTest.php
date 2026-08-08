<?php

namespace Tests\Feature\Repository;

use App\Models\Project;
use App\Models\RepositoryConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGitHub;
use Tests\TestCase;

class RepositoryConnectionAccessTest extends TestCase
{
    use FakesGitHub, RefreshDatabase;

    private const TOKEN = 'github_pat_11ABCDEFG0abcdefghijkl';

    /**
     * The payload a write route expects, so that a refusal is never mistaken
     * for a validation error.
     *
     * @return array<string, string>
     */
    private function payload(): array
    {
        return [
            'repository_url' => 'https://github.com/acme/platform',
            'token' => self::TOKEN,
        ];
    }

    public function test_a_guest_is_redirected_to_login_on_every_repository_route(): void
    {
        $project = Project::factory()->create();

        $this->get(route('projects.repository.edit', $project))->assertRedirect(route('login'));
        $this->post(route('projects.repository.store', $project))->assertRedirect(route('login'));
        $this->put(route('projects.repository.update', $project))->assertRedirect(route('login'));
        $this->delete(route('projects.repository.destroy', $project))->assertRedirect(route('login'));
        $this->post(route('projects.repository.check', $project))->assertRedirect(route('login'));
    }

    public function test_a_non_owner_is_forbidden_on_every_repository_route(): void
    {
        Http::fake();

        $project = Project::factory()->create();
        RepositoryConnection::factory()->forProject($project)->create(['github_id' => 123456]);

        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->get(route('projects.repository.edit', $project))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->post(route('projects.repository.store', $project), $this->payload())
            ->assertForbidden();

        $this->actingAs($intruder)
            ->put(route('projects.repository.update', $project), $this->payload())
            ->assertForbidden();

        $this->actingAs($intruder)
            ->delete(route('projects.repository.destroy', $project))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->post(route('projects.repository.check', $project))
            ->assertForbidden();

        // A refusal must never have spent the owner's GitHub quota.
        $this->assertNoGitHubCall();

        $connection = $project->repositoryConnection()->first();

        $this->assertNotNull($connection);
        $this->assertSame(123456, $connection->github_id);
    }

    public function test_the_write_routes_are_rate_limited(): void
    {
        Http::fake();

        $project = Project::factory()->create();
        $owner = $project->owner;

        // Ten refused attempts still count: the limiter guards the quota, not
        // the outcome.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->actingAs($owner)
                ->post(route('projects.repository.store', $project), [
                    'repository_url' => 'not a repository',
                    'token' => self::TOKEN,
                ])
                ->assertRedirect();
        }

        $this->actingAs($owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'not a repository',
                'token' => self::TOKEN,
            ])
            ->assertStatus(429);
    }

    public function test_reading_the_screen_is_not_rate_limited(): void
    {
        $project = Project::factory()->create();

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $this->actingAs($project->owner)
                ->get(route('projects.repository.edit', $project))
                ->assertOk();
        }
    }
}
