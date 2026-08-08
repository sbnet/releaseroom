<?php

namespace Tests\Feature\Repository;

use App\Enums\ConnectionFailure;
use App\Models\Project;
use App\Models\RepositoryConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesGitHub;
use Tests\TestCase;

/**
 * Every way GitHub can say no, and where the owner is told about it.
 *
 * The invariant under test throughout: a refusal never leaves a connection
 * behind. What exists in the database was verified at least once.
 */
class RepositoryConnectionFailureTest extends TestCase
{
    use FakesGitHub, RefreshDatabase;

    private const TOKEN = 'github_pat_11ABCDEFG0abcdefghijkl';

    private function connect(Project $project): TestResponse
    {
        return $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ]);
    }

    public function test_a_rejected_token_is_reported_on_the_token_field(): void
    {
        $this->fakeGitHubRepositoryFailure(401);
        $project = Project::factory()->create();

        $this->connect($project)->assertSessionHasErrors([
            'token' => ConnectionFailure::InvalidToken->message(),
        ]);

        $this->assertSame(0, RepositoryConnection::count());
    }

    public function test_a_token_without_access_is_reported_on_the_token_field(): void
    {
        $this->fakeGitHubRepositoryFailure(403);
        $project = Project::factory()->create();

        $this->connect($project)->assertSessionHasErrors([
            'token' => ConnectionFailure::InsufficientAccess->message(),
        ]);

        $this->assertSame(0, RepositoryConnection::count());
    }

    public function test_an_unknown_repository_is_reported_on_the_address_field(): void
    {
        $this->fakeGitHubRepositoryFailure(404);
        $project = Project::factory()->create();

        $this->connect($project)->assertSessionHasErrors([
            'repository_url' => ConnectionFailure::RepositoryNotFound->message(),
        ]);

        $this->assertSame(0, RepositoryConnection::count());
    }

    public function test_a_token_that_cannot_read_pull_requests_is_refused(): void
    {
        $this->fakeGitHubPullsFailure(403);
        $project = Project::factory()->create();

        $this->connect($project)->assertSessionHasErrors([
            'token' => ConnectionFailure::MissingPullScope->message(),
        ]);

        $this->assertSame(0, RepositoryConnection::count());
    }

    public function test_a_404_on_the_pull_requests_is_read_as_a_missing_permission(): void
    {
        $this->fakeGitHubPullsFailure(404);
        $project = Project::factory()->create();

        $this->connect($project)->assertSessionHasErrors([
            'token' => ConnectionFailure::MissingPullScope->message(),
        ]);

        $this->assertSame(0, RepositoryConnection::count());
    }

    public function test_an_exhausted_quota_is_told_apart_from_a_missing_permission(): void
    {
        $this->fakeGitHubRepositoryFailure(403, ['x-ratelimit-remaining' => '0']);
        $project = Project::factory()->create();

        $this->connect($project)->assertSessionHasErrors([
            'github' => ConnectionFailure::RateLimited->message(),
        ]);

        $this->assertSame(0, RepositoryConnection::count());
    }

    public function test_a_secondary_rate_limit_is_reported_as_a_rate_limit(): void
    {
        $this->fakeGitHubRepositoryFailure(429, ['retry-after' => '60']);
        $project = Project::factory()->create();

        $this->connect($project)->assertSessionHasErrors([
            'github' => ConnectionFailure::RateLimited->message(),
        ]);

        $this->assertSame(0, RepositoryConnection::count());
    }

    public function test_github_being_down_is_reported_at_form_level(): void
    {
        $this->fakeGitHubRepositoryFailure(503);
        $project = Project::factory()->create();

        $this->connect($project)->assertSessionHasErrors([
            'github' => ConnectionFailure::GithubUnavailable->message(),
        ]);

        $this->assertSame(0, RepositoryConnection::count());
    }

    public function test_github_being_unreachable_is_reported_at_form_level(): void
    {
        $this->fakeGitHubUnreachable();
        $project = Project::factory()->create();

        $this->connect($project)->assertSessionHasErrors([
            'github' => ConnectionFailure::GithubUnavailable->message(),
        ]);

        $this->assertSame(0, RepositoryConnection::count());
    }

    public function test_an_unusable_repository_payload_is_refused(): void
    {
        // A 200 with nothing we can identify the repository by is not a
        // success we can store.
        $this->fakeGitHub(['id' => null, 'full_name' => null]);
        $project = Project::factory()->create();

        $this->connect($project)->assertSessionHasErrors([
            'github' => ConnectionFailure::GithubUnavailable->message(),
        ]);

        $this->assertSame(0, RepositoryConnection::count());
    }
}
