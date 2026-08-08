<?php

namespace Tests\Feature\Repository;

use App\Enums\ConnectionStatus;
use App\Models\Project;
use App\Models\RepositoryConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGitHub;
use Tests\TestCase;

class RepositoryConnectionStoreTest extends TestCase
{
    use FakesGitHub, RefreshDatabase;

    private const TOKEN = 'github_pat_11ABCDEFG0abcdefghijkl';

    public function test_the_connect_screen_is_displayed(): void
    {
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->get(route('projects.repository.edit', $project))
            ->assertOk();
    }

    public function test_an_owner_can_connect_a_repository(): void
    {
        $this->fakeGitHub();
        $project = Project::factory()->create();

        $response = $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('projects.show', $project));

        $connection = $project->repositoryConnection()->first();

        $this->assertNotNull($connection);
        $this->assertSame(123456, $connection->github_id);
        $this->assertSame('acme', $connection->owner);
        $this->assertSame('platform', $connection->name);
        $this->assertFalse($connection->is_private);
        $this->assertSame('main', $connection->default_branch);
        $this->assertSame(ConnectionStatus::Connected, $connection->status);
        $this->assertNull($connection->last_error_code);
        $this->assertSame($project->user_id, $connection->user_id);
    }

    public function test_both_github_endpoints_are_called_with_the_token(): void
    {
        $this->fakeGitHub();
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/repos/acme/platform'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.github.com/repos/acme/platform/pulls')
            && str_contains($request->url(), 'state=closed'));
    }

    public function test_the_canonical_name_github_returns_is_what_gets_stored(): void
    {
        // The owner pastes the old address; GitHub answers with the new one.
        $this->fakeGitHub(['full_name' => 'acme-inc/platform-v2']);
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ])
            ->assertSessionHasNoErrors();

        $connection = $project->repositoryConnection()->firstOrFail();

        $this->assertSame('acme-inc', $connection->owner);
        $this->assertSame('platform-v2', $connection->name);
    }

    public function test_a_private_repository_and_its_default_branch_are_recorded(): void
    {
        $this->fakeGitHub(['private' => true, 'default_branch' => 'trunk']);
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ])
            ->assertSessionHasNoErrors();

        $connection = $project->repositoryConnection()->firstOrFail();

        $this->assertTrue($connection->is_private);
        $this->assertSame('trunk', $connection->default_branch);
    }

    public function test_the_token_expiry_header_is_captured_when_github_reports_one(): void
    {
        $this->fakeGitHub(headers: [
            'github-authentication-token-expiration' => '2026-12-31 23:59:59 UTC',
        ]);
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ])
            ->assertSessionHasNoErrors();

        $connection = $project->repositoryConnection()->firstOrFail();

        $this->assertNotNull($connection->token_expires_at);
        $this->assertSame('2026-12-31', $connection->token_expires_at->toDateString());
    }

    public function test_the_expiry_stays_unknown_when_github_reports_none(): void
    {
        $this->fakeGitHub();
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($project->repositoryConnection()->firstOrFail()->token_expires_at);
    }

    public function test_the_token_is_encrypted_at_rest(): void
    {
        $this->fakeGitHub();
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ])
            ->assertSessionHasNoErrors();

        $stored = DB::table('repository_connections')
            ->where('project_id', $project->id)
            ->value('token');

        $this->assertIsString($stored);
        $this->assertNotSame(self::TOKEN, $stored);
        $this->assertStringNotContainsString(self::TOKEN, $stored);

        // And it still decrypts back to the token the owner pasted.
        $this->assertSame(self::TOKEN, $project->repositoryConnection()->firstOrFail()->token);
    }

    public function test_only_the_last_four_characters_are_kept_for_display(): void
    {
        $this->fakeGitHub();
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('ijkl', $project->repositoryConnection()->firstOrFail()->token_last_four);
    }

    public function test_the_token_never_travels_back_to_the_client(): void
    {
        $this->fakeGitHub();
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ]);

        $this->actingAs($project->owner)
            ->get(route('projects.repository.edit', $project))
            ->assertOk()
            ->assertDontSee(self::TOKEN, escape: false);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee(self::TOKEN, escape: false);
    }

    public function test_a_validation_failure_does_not_flash_the_token_back(): void
    {
        $this->fakeGitHubRepositoryFailure(401);
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ])
            ->assertSessionHasErrors('token');

        $this->assertNull(session()->getOldInput('token'));
        $this->assertSame('https://github.com/acme/platform', session()->getOldInput('repository_url'));
    }

    public function test_a_malformed_address_is_rejected_before_github_is_called(): void
    {
        Http::fake();
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://gitlab.com/acme/platform',
                'token' => self::TOKEN,
            ])
            ->assertSessionHasErrors('repository_url');

        $this->assertNoGitHubCall();
        $this->assertSame(0, RepositoryConnection::count());
    }

    public function test_a_missing_address_or_token_is_rejected(): void
    {
        Http::fake();
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => '',
                'token' => '',
            ])
            ->assertSessionHasErrors(['repository_url', 'token']);

        $this->assertNoGitHubCall();
        $this->assertSame(0, RepositoryConnection::count());
    }

    public function test_a_token_containing_whitespace_is_rejected(): void
    {
        Http::fake();
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => 'github_pat_ broken',
            ])
            ->assertSessionHasErrors('token');

        $this->assertNoGitHubCall();
        $this->assertSame(0, RepositoryConnection::count());
    }

    public function test_surrounding_whitespace_is_trimmed_from_both_fields(): void
    {
        $this->fakeGitHub();
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => "  https://github.com/acme/platform  \n",
                'token' => '  '.self::TOKEN."\n",
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(self::TOKEN, $project->repositoryConnection()->firstOrFail()->token);
    }

    public function test_a_project_that_already_has_a_repository_is_rejected(): void
    {
        $project = Project::factory()->create();
        RepositoryConnection::factory()->forProject($project)->create();

        Http::fake();

        $this->actingAs($project->owner)
            ->post(route('projects.repository.store', $project), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ])
            ->assertSessionHasErrors('repository_url');

        $this->assertNoGitHubCall();
        $this->assertSame(1, RepositoryConnection::count());
    }

    public function test_the_same_repository_on_a_second_project_of_the_same_owner_is_rejected(): void
    {
        $this->fakeGitHub();

        $owner = User::factory()->create();
        $first = Project::factory()->for($owner, 'owner')->create(['name' => 'Acme Platform']);
        $second = Project::factory()->for($owner, 'owner')->create();

        RepositoryConnection::factory()->forProject($first)->create(['github_id' => 123456]);

        $this->actingAs($owner)
            ->post(route('projects.repository.store', $second), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ])
            ->assertSessionHasErrors([
                'repository_url' => 'This repository is already connected to Acme Platform.',
            ]);

        $this->assertNull($second->repositoryConnection()->first());
    }

    public function test_another_user_can_connect_the_same_repository(): void
    {
        $this->fakeGitHub();

        $theirs = Project::factory()->create();
        RepositoryConnection::factory()->forProject($theirs)->create(['github_id' => 123456]);

        $mine = Project::factory()->create();

        $this->actingAs($mine->owner)
            ->post(route('projects.repository.store', $mine), [
                'repository_url' => 'https://github.com/acme/platform',
                'token' => self::TOKEN,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(123456, $mine->repositoryConnection()->firstOrFail()->github_id);
    }
}
