<?php

use App\Models\Project;
use App\Models\PullRequestCandidate;
use App\Models\RepositoryConnection;
use App\Models\User;
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

it('tells the repository screen how many pull requests are in the way', function () {
    PullRequestCandidate::factory()->forProject($this->project)->count(4)->create();

    $this->actingAs($this->owner)
        ->get("/projects/{$this->project->id}/repository")
        ->assertInertia(fn ($page) => $page->where('candidate_count', 4));
});
