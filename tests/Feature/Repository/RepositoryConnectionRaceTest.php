<?php

namespace Tests\Feature\Repository;

use App\Models\Project;
use App\Models\RepositoryConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGitHub;
use Tests\TestCase;

/**
 * Two submissions crossing each other.
 *
 * The application-level checks run before the GitHub round trip, so anything
 * that lands during that round trip slips past them. The unique indexes are
 * what actually holds; these tests pin down that the owner gets the message
 * the checks would have given rather than a 500.
 *
 * The competing row is inserted from inside the faked GitHub response, which
 * is exactly the window a real second request would land in.
 */
class RepositoryConnectionRaceTest extends TestCase
{
    use FakesGitHub, RefreshDatabase;

    private const TOKEN = 'github_pat_11ABCDEFG0abcdefghijkl';

    /**
     * Fake GitHub, running the given side effect while the call is in flight.
     */
    private function fakeGitHubMeanwhile(callable $meanwhile): void
    {
        $done = false;

        Http::fake(function () use ($meanwhile, &$done) {
            if (! $done) {
                $done = true;
                $meanwhile();
            }

            return Http::response($this->githubRepository(), 200);
        });
    }

    public function test_a_second_connection_landing_mid_flight_is_refused_cleanly(): void
    {
        $project = Project::factory()->create();

        // Another submission connects a different repository to this same
        // project while we are still talking to GitHub.
        $this->fakeGitHubMeanwhile(function () use ($project) {
            RepositoryConnection::factory()->forProject($project)->create([
                'github_id' => 987654,
            ]);
        });

        $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ])
            ->assertSessionHasErrors([
                'repository_url' => 'This project already has a repository connected.',
            ]);

        // The index held: the row that got there first is the only one.
        $this->assertSame(1, RepositoryConnection::count());
        $this->assertSame(987654, $project->repositoryConnection()->firstOrFail()->github_id);
    }

    public function test_the_same_repository_landing_mid_flight_names_the_project_that_won(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $rival = Project::factory()->for($owner, 'owner')->create(['name' => 'Acme Website']);

        // The owner connects the same repository to another of their projects
        // while we are still talking to GitHub.
        $this->fakeGitHubMeanwhile(function () use ($rival) {
            RepositoryConnection::factory()->forProject($rival)->create([
                'github_id' => 123456,
            ]);
        });

        $this->actingAs($owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ])
            ->assertSessionHasErrors([
                'repository_url' => 'This repository is already connected to Acme Website.',
            ]);

        $this->assertSame(1, RepositoryConnection::count());
        $this->assertNull($project->repositoryConnection()->first());
    }
}
