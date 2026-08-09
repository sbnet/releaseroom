<?php

use App\Enums\WebhookStatus;
use App\Models\Project;
use App\Models\PullRequestCandidate;
use App\Models\RepositoryConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGitHub;

uses(FakesGitHub::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->project = Project::factory()->for($this->owner, 'owner')->create();
    $this->connection = RepositoryConnection::factory()
        ->forProject($this->project)
        ->create([
            'github_id' => 123456,
            'owner' => 'acme',
            'name' => 'platform',
        ]);
});

it('refuses to repoint a project that already holds pull requests', function () {
    PullRequestCandidate::factory()->forProject($this->project)->create();

    $this->fakeGitHub(['id' => 999999, 'full_name' => 'acme/other']);

    $this->actingAs($this->owner)
        ->put("/projects/{$this->project->id}/repository", [
            'repository_url' => 'https://github.com/acme/other',
            'token' => 'github_pat_valid_token_value',
        ])
        ->assertSessionHasErrors('repository_url');

    expect($this->connection->fresh())
        ->github_id->toBe(123456)
        ->owner->toBe('acme')
        ->name->toBe('platform');
});

it('refuses even when the only candidates left are dismissed', function () {
    PullRequestCandidate::factory()->forProject($this->project)->dismissed()->create();

    $this->fakeGitHub(['id' => 999999, 'full_name' => 'acme/other']);

    $this->actingAs($this->owner)
        ->put("/projects/{$this->project->id}/repository", [
            'repository_url' => 'https://github.com/acme/other',
            'token' => 'github_pat_valid_token_value',
        ])
        ->assertSessionHasErrors('repository_url');

    expect($this->connection->fresh()->github_id)->toBe(123456);
});

it('allows repointing while the project holds nothing', function () {
    $this->fakeGitHub(['id' => 999999, 'full_name' => 'acme/other']);

    $this->actingAs($this->owner)
        ->put("/projects/{$this->project->id}/repository", [
            'repository_url' => 'https://github.com/acme/other',
            'token' => 'github_pat_valid_token_value',
        ])
        ->assertSessionHasNoErrors();

    expect($this->connection->fresh())
        ->github_id->toBe(999999)
        ->name->toBe('other');
});

it('still lets the owner replace the token on a project with pull requests', function () {
    PullRequestCandidate::factory()->forProject($this->project)->create();

    $this->fakeGitHub();

    $this->actingAs($this->owner)
        ->put("/projects/{$this->project->id}/repository", [
            'repository_url' => 'https://github.com/acme/platform',
            'token' => 'github_pat_a_brand_new_token_value',
        ])
        ->assertSessionHasNoErrors();

    expect($this->connection->fresh())
        ->token->toBe('github_pat_a_brand_new_token_value')
        ->github_id->toBe(123456);
});

it('follows a rename without complaining, candidates or not', function () {
    PullRequestCandidate::factory()->forProject($this->project)->create();

    /* Same numeric id, new address: a rename, not a repointing. */
    $this->fakeGitHub(['id' => 123456, 'full_name' => 'acme/platform-renamed']);

    $this->actingAs($this->owner)
        ->put("/projects/{$this->project->id}/repository", [
            'repository_url' => 'https://github.com/acme/platform-renamed',
            'token' => 'github_pat_valid_token_value',
        ])
        ->assertSessionHasNoErrors();

    expect($this->connection->fresh())
        ->github_id->toBe(123456)
        ->name->toBe('platform-renamed');
});

it('leaves the candidates alone when a repointing is refused', function () {
    PullRequestCandidate::factory()->forProject($this->project)->count(3)->create();

    $this->fakeGitHub(['id' => 999999, 'full_name' => 'acme/other']);

    $this->actingAs($this->owner)
        ->put("/projects/{$this->project->id}/repository", [
            'repository_url' => 'https://github.com/acme/other',
            'token' => 'github_pat_valid_token_value',
        ])
        ->assertSessionHasErrors('repository_url');

    expect($this->project->pullRequestCandidates()->count())->toBe(3);
});

it('keeps the hook when the pre-check refuses a repoint', function () {
    /*
     * The owner already reads acme/other from another project, so the
     * pre-check refuses this repoint. Nothing about the old connection —
     * least of all its live hook on GitHub — may be touched on the way out.
     */
    $rival = Project::factory()->for($this->owner, 'owner')->create();
    RepositoryConnection::factory()->forProject($rival)->create(['github_id' => 999999]);

    $this->connection->webhook_id = 4242;
    $this->connection->webhook_status = WebhookStatus::Active;
    $this->connection->save();

    $this->fakeGitHub(['id' => 999999, 'full_name' => 'acme/other']);

    $this->actingAs($this->owner)
        ->put("/projects/{$this->project->id}/repository", [
            'repository_url' => 'https://github.com/acme/other',
            'token' => 'github_pat_valid_token_value',
        ])
        ->assertSessionHasErrors('repository_url');

    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');

    expect($this->connection->fresh())
        ->github_id->toBe(123456)
        ->webhook_id->toBe(4242)
        ->webhook_status->toBe(WebhookStatus::Active);
});

it('keeps the hook when a repoint loses the race to the unique index', function () {
    /*
     * The genuine lost race, which the pre-checks cannot see: a competing
     * connection to the same repository lands after they have run and before
     * this one is written, so the unique index is what refuses it.
     *
     * The old hook must still be on GitHub afterwards. Deleting it first would
     * leave this owner with an unchanged connection pointing at a hook that no
     * longer exists — and nothing on screen would say so.
     */
    $rival = Project::factory()->for($this->owner, 'owner')->create(['name' => 'Acme Website']);

    $landed = false;

    RepositoryConnection::saving(function () use (&$landed, $rival) {
        if ($landed) {
            return;
        }

        $landed = true;

        RepositoryConnection::factory()->forProject($rival)->create(['github_id' => 999999]);
    });

    $this->connection->webhook_id = 4242;
    $this->connection->webhook_status = WebhookStatus::Active;
    $this->connection->saveQuietly();

    $this->fakeGitHub(['id' => 999999, 'full_name' => 'acme/other']);

    $this->actingAs($this->owner)
        ->put("/projects/{$this->project->id}/repository", [
            'repository_url' => 'https://github.com/acme/other',
            'token' => 'github_pat_valid_token_value',
        ])
        ->assertSessionHasErrors([
            'repository_url' => 'This repository is already connected to Acme Website.',
        ]);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');

    expect($this->connection->fresh())
        ->github_id->toBe(123456)
        ->webhook_id->toBe(4242)
        ->webhook_status->toBe(WebhookStatus::Active);
});

it('removes the old hook only once a repoint has actually been written', function () {
    $this->connection->webhook_id = 4242;
    $this->connection->webhook_status = WebhookStatus::Active;
    $this->connection->save();

    $this->fakeGitHub(['id' => 999999, 'full_name' => 'acme/other']);

    $this->actingAs($this->owner)
        ->put("/projects/{$this->project->id}/repository", [
            'repository_url' => 'https://github.com/acme/other',
            'token' => 'github_pat_valid_token_value',
        ])
        ->assertSessionHasNoErrors();

    /* Deleted from the repository it used to watch, not the new one. */
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/repos/acme/platform/hooks/4242'));

    expect($this->connection->fresh())
        ->github_id->toBe(999999)
        ->webhook_id->toBeNull();
});

it('tells the repository screen how many pull requests are in the way', function () {
    PullRequestCandidate::factory()->forProject($this->project)->count(4)->create();

    $this->actingAs($this->owner)
        ->get("/projects/{$this->project->id}/repository")
        ->assertInertia(fn ($page) => $page->where('candidate_count', 4));
});
