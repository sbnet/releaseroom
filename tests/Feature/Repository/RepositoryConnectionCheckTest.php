<?php

namespace Tests\Feature\Repository;

use App\Enums\ConnectionFailure;
use App\Enums\ConnectionStatus;
use App\Models\Project;
use App\Models\RepositoryConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGitHub;
use Tests\TestCase;

/**
 * Re-verification is the one action allowed to record a failure: by the time
 * it runs, a working connection already exists, and throwing it away would
 * punish the owner for a token someone else revoked.
 */
class RepositoryConnectionCheckTest extends TestCase
{
    use FakesGitHub, RefreshDatabase;

    private const TOKEN = 'github_pat_11ABCDEFG0abcdefghijkl';

    private function connected(ConnectionFailure|false $failure = false): RepositoryConnection
    {
        $project = Project::factory()->create();

        $factory = RepositoryConnection::factory()->forProject($project);

        if ($failure !== false) {
            $factory = $factory->failed($failure);
        }

        $connection = $factory->make([
            'github_id' => 123456,
            'owner' => 'acme',
            'name' => 'platform',
            'last_checked_at' => now()->subDay(),
        ]);

        $connection->setToken(self::TOKEN);
        $connection->save();

        return $connection;
    }

    public function test_a_successful_check_clears_a_previous_failure(): void
    {
        $this->fakeGitHub();
        $connection = $this->connected(ConnectionFailure::InvalidToken);

        $this->actingAs($connection->project->owner)
            ->post(route('projects.repository.check', $connection->project))
            ->assertRedirect();

        $fresh = $connection->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame(ConnectionStatus::Connected, $fresh->status);
        $this->assertNull($fresh->last_error_code);
        $this->assertTrue($fresh->last_checked_at->isToday());

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }

    public function test_a_renamed_repository_is_followed_rather_than_broken(): void
    {
        // Same numeric id, new address: the repository simply moved.
        $this->fakeGitHub(['id' => 123456, 'full_name' => 'acme-inc/platform-v2']);
        $connection = $this->connected();

        $this->actingAs($connection->project->owner)
            ->post(route('projects.repository.check', $connection->project))
            ->assertRedirect();

        $fresh = $connection->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame('acme-inc', $fresh->owner);
        $this->assertSame('platform-v2', $fresh->name);
        $this->assertSame(ConnectionStatus::Connected, $fresh->status);
    }

    public function test_a_different_repository_at_the_same_address_is_flagged(): void
    {
        // Same address, different numeric id: this is somebody else's
        // repository now, and none of the stored metadata may be trusted.
        $this->fakeGitHub(['id' => 987654, 'full_name' => 'acme/platform']);
        $connection = $this->connected();

        $this->actingAs($connection->project->owner)
            ->post(route('projects.repository.check', $connection->project))
            ->assertRedirect();

        $fresh = $connection->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame(ConnectionStatus::Failed, $fresh->status);
        $this->assertSame(ConnectionFailure::IdentityChanged, $fresh->last_error_code);
        $this->assertSame(123456, $fresh->github_id);
        $this->assertSame('platform', $fresh->name);
    }

    public function test_a_revoked_token_marks_the_connection_failed_without_losing_it(): void
    {
        $this->fakeGitHubRepositoryFailure(401);
        $connection = $this->connected();

        $this->actingAs($connection->project->owner)
            ->post(route('projects.repository.check', $connection->project))
            ->assertRedirect();

        $fresh = $connection->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame(ConnectionStatus::Failed, $fresh->status);
        $this->assertSame(ConnectionFailure::InvalidToken, $fresh->last_error_code);
        $this->assertSame(self::TOKEN, $fresh->token);
        $this->assertTrue($fresh->last_checked_at->isToday());
    }

    public function test_github_being_down_is_recorded_as_such_not_as_a_bad_token(): void
    {
        $this->fakeGitHubUnreachable();
        $connection = $this->connected();

        $this->actingAs($connection->project->owner)
            ->post(route('projects.repository.check', $connection->project))
            ->assertRedirect();

        $fresh = $connection->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame(ConnectionFailure::GithubUnavailable, $fresh->last_error_code);
    }

    public function test_a_lost_permission_is_recorded_on_a_recheck(): void
    {
        $this->fakeGitHubPullsFailure(403);
        $connection = $this->connected();

        $this->actingAs($connection->project->owner)
            ->post(route('projects.repository.check', $connection->project))
            ->assertRedirect();

        $fresh = $connection->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame(ConnectionFailure::MissingPullScope, $fresh->last_error_code);
    }

    public function test_the_failure_is_shown_on_the_project_page(): void
    {
        $this->fakeGitHubRepositoryFailure(401);
        $connection = $this->connected();

        $this->actingAs($connection->project->owner)
            ->post(route('projects.repository.check', $connection->project));

        $this->actingAs($connection->project->owner)
            ->get(route('projects.show', $connection->project))
            ->assertOk()
            ->assertSee(ConnectionFailure::InvalidToken->message(), escape: false);
    }

    public function test_checking_a_project_without_a_connection_is_not_found(): void
    {
        Http::fake();
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.repository.check', $project))
            ->assertNotFound();

        $this->assertNoGitHubCall();
    }
}
