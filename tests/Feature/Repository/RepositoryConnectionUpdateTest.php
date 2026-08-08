<?php

namespace Tests\Feature\Repository;

use App\Enums\ConnectionFailure;
use App\Models\Project;
use App\Models\RepositoryConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGitHub;
use Tests\TestCase;

class RepositoryConnectionUpdateTest extends TestCase
{
    use FakesGitHub, RefreshDatabase;

    private const OLD_TOKEN = 'github_pat_oldoldoldoldoldold0000';

    private const NEW_TOKEN = 'github_pat_newnewnewnewnewnew9999';

    private function connected(?Project $project = null): RepositoryConnection
    {
        $project ??= Project::factory()->create();

        $connection = RepositoryConnection::factory()->forProject($project)->make([
            'github_id' => 123456,
            'owner' => 'acme',
            'name' => 'platform',
        ]);

        $connection->setToken(self::OLD_TOKEN);
        $connection->save();

        return $connection;
    }

    public function test_the_manage_screen_is_displayed_with_the_fingerprint(): void
    {
        $connection = $this->connected();

        $this->actingAs($connection->project->owner)
            ->get(route('projects.repository.edit', $connection->project))
            ->assertOk()
            ->assertSee(substr(self::OLD_TOKEN, -4));
    }

    public function test_the_owner_can_replace_the_token(): void
    {
        $this->fakeGitHub();
        $connection = $this->connected();

        $this->actingAs($connection->project->owner)
            ->put(route('projects.repository.update', $connection->project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::NEW_TOKEN,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('projects.show', $connection->project));

        $fresh = $connection->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame(self::NEW_TOKEN, $fresh->token);
        $this->assertSame('9999', $fresh->token_last_four);

        // The new token, not the old one, is what was put to GitHub.
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer '.self::NEW_TOKEN));
    }

    public function test_a_blank_token_keeps_the_stored_one_and_reverifies_with_it(): void
    {
        $this->fakeGitHub();
        $connection = $this->connected();

        $this->actingAs($connection->project->owner)
            ->put(route('projects.repository.update', $connection->project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => '',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $connection->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame(self::OLD_TOKEN, $fresh->token);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer '.self::OLD_TOKEN));
    }

    public function test_the_owner_can_repoint_the_connection_to_another_repository(): void
    {
        $this->fakeGitHub([
            'id' => 999999,
            'full_name' => 'acme/website',
            'private' => true,
            'default_branch' => 'trunk',
        ]);
        $connection = $this->connected();

        $this->actingAs($connection->project->owner)
            ->put(route('projects.repository.update', $connection->project), [
                'repository_url' => 'https://github.com/acme/website',
                'token' => '',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $connection->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame(999999, $fresh->github_id);
        $this->assertSame('website', $fresh->name);
        $this->assertTrue($fresh->is_private);
        $this->assertSame('trunk', $fresh->default_branch);
    }

    public function test_a_refused_update_leaves_the_connection_untouched(): void
    {
        $this->fakeGitHubRepositoryFailure(401);
        $connection = $this->connected();

        $this->actingAs($connection->project->owner)
            ->put(route('projects.repository.update', $connection->project), [
                'repository_url' => 'https://github.com/acme/website',
                'token' => self::NEW_TOKEN,
            ])
            ->assertSessionHasErrors([
                'token' => ConnectionFailure::InvalidToken->message(),
            ]);

        $fresh = $connection->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame(self::OLD_TOKEN, $fresh->token);
        $this->assertSame(123456, $fresh->github_id);
        $this->assertSame('platform', $fresh->name);
        $this->assertTrue($fresh->isConnected());
    }

    public function test_repointing_onto_a_repository_the_owner_already_reads_is_rejected(): void
    {
        $this->fakeGitHub(['id' => 555, 'full_name' => 'acme/website']);

        $owner = User::factory()->create();
        $other = Project::factory()->for($owner, 'owner')->create(['name' => 'Acme Website']);
        RepositoryConnection::factory()->forProject($other)->create(['github_id' => 555]);

        $connection = $this->connected(Project::factory()->for($owner, 'owner')->create());

        $this->actingAs($owner)
            ->put(route('projects.repository.update', $connection->project), [
                'repository_url' => 'https://github.com/acme/website',
                'token' => '',
            ])
            ->assertSessionHasErrors([
                'repository_url' => 'This repository is already connected to Acme Website.',
            ]);

        $fresh = $connection->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame(123456, $fresh->github_id);
    }

    public function test_keeping_the_same_repository_is_not_a_duplicate_of_itself(): void
    {
        $this->fakeGitHub();
        $connection = $this->connected();

        $this->actingAs($connection->project->owner)
            ->put(route('projects.repository.update', $connection->project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::NEW_TOKEN,
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_a_malformed_address_is_rejected_before_github_is_called(): void
    {
        Http::fake();
        $connection = $this->connected();

        $this->actingAs($connection->project->owner)
            ->put(route('projects.repository.update', $connection->project), [
                'repository_url' => 'https://gitlab.com/acme/platform',
                'token' => '',
            ])
            ->assertSessionHasErrors('repository_url');

        $this->assertNoGitHubCall();
    }

    public function test_updating_a_project_without_a_connection_is_not_found(): void
    {
        Http::fake();
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->put(route('projects.repository.update', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::NEW_TOKEN,
            ])
            ->assertNotFound();

        $this->assertNoGitHubCall();
    }
}
