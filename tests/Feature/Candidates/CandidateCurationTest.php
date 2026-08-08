<?php

use App\Enums\CandidateState;
use App\Models\Project;
use App\Models\PullRequestCandidate;
use App\Models\RepositoryConnection;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->project = Project::factory()->for($this->owner, 'owner')->create();
});

it('lists the pending pull requests, newest merge first', function () {
    PullRequestCandidate::factory()->forProject($this->project)->create([
        'number' => 1,
        'merged_at' => now()->subDays(3),
    ]);
    PullRequestCandidate::factory()->forProject($this->project)->create([
        'number' => 2,
        'merged_at' => now()->subDay(),
    ]);
    PullRequestCandidate::factory()->forProject($this->project)->dismissed()->create([
        'number' => 3,
    ]);

    $this->actingAs($this->owner)
        ->get("/projects/{$this->project->id}/candidates")
        ->assertInertia(fn ($page) => $page
            ->component('projects/candidates/Index')
            ->where('state', 'pending')
            ->where('counts.pending', 2)
            ->where('counts.dismissed', 1)
            ->has('candidates.data', 2)
            ->where('candidates.data.0.number', 2)
            ->where('candidates.data.1.number', 1));
});

it('lists the dismissed pull requests on request', function () {
    PullRequestCandidate::factory()->forProject($this->project)->create(['number' => 1]);
    PullRequestCandidate::factory()->forProject($this->project)->dismissed()->create(['number' => 3]);

    $this->actingAs($this->owner)
        ->get("/projects/{$this->project->id}/candidates?state=dismissed")
        ->assertInertia(fn ($page) => $page
            ->where('state', 'dismissed')
            ->has('candidates.data', 1)
            ->where('candidates.data.0.number', 3));
});

it('falls back to the pending list for an unknown state', function () {
    PullRequestCandidate::factory()->forProject($this->project)->create();

    $this->actingAs($this->owner)
        ->get("/projects/{$this->project->id}/candidates?state=nonsense")
        ->assertInertia(fn ($page) => $page->where('state', 'pending')->has('candidates.data', 1));
});

it('paginates at twenty', function () {
    PullRequestCandidate::factory()->forProject($this->project)->count(25)->create();

    $this->actingAs($this->owner)
        ->get("/projects/{$this->project->id}/candidates")
        ->assertInertia(fn ($page) => $page
            ->has('candidates.data', 20)
            ->where('candidates.total', 25)
            ->where('candidates.last_page', 2));

    $this->actingAs($this->owner)
        ->get("/projects/{$this->project->id}/candidates?page=2")
        ->assertInertia(fn ($page) => $page->has('candidates.data', 5));
});

it('carries no pull request body to the list', function () {
    PullRequestCandidate::factory()->forProject($this->project)->create([
        'body' => 'A body nobody needs in order to triage.',
    ]);

    $this->actingAs($this->owner)
        ->get("/projects/{$this->project->id}/candidates")
        ->assertInertia(fn ($page) => $page->missing('candidates.data.0.body'));
});

it('dismisses a pull request', function () {
    $candidate = PullRequestCandidate::factory()->forProject($this->project)->create();

    $this->actingAs($this->owner)
        ->post("/projects/{$this->project->id}/candidates/{$candidate->id}/dismiss")
        ->assertRedirect();

    expect($candidate->fresh())
        ->state->toBe(CandidateState::Dismissed)
        ->curated_at->not->toBeNull();
});

it('restores a dismissed pull request, and keeps it curated', function () {
    $candidate = PullRequestCandidate::factory()
        ->forProject($this->project)
        ->dismissed()
        ->create();

    $curatedAt = $candidate->curated_at;

    $this->actingAs($this->owner)
        ->post("/projects/{$this->project->id}/candidates/{$candidate->id}/restore")
        ->assertRedirect();

    expect($candidate->fresh())
        ->state->toBe(CandidateState::Pending)
        /* Restoring is a ruling too, and stays one. */
        ->curated_at->not->toBeNull()
        ->and($candidate->fresh()->curated_at->toIso8601String())
        ->toBe($curatedAt->toIso8601String());
});

it('shows the pending count on the project page', function () {
    RepositoryConnection::factory()->forProject($this->project)->create();
    PullRequestCandidate::factory()->forProject($this->project)->count(3)->create();
    PullRequestCandidate::factory()->forProject($this->project)->dismissed()->count(2)->create();

    $this->actingAs($this->owner)
        ->get("/projects/{$this->project->id}")
        ->assertInertia(fn ($page) => $page->where('pending_count', 3));
});

it('keeps a project\'s candidates when its repository is disconnected', function () {
    RepositoryConnection::factory()->forProject($this->project)->create();
    PullRequestCandidate::factory()->forProject($this->project)->count(3)->create();

    $this->actingAs($this->owner)
        ->delete("/projects/{$this->project->id}/repository")
        ->assertRedirect();

    expect($this->project->repositoryConnection()->exists())->toBeFalse()
        ->and($this->project->pullRequestCandidates()->count())->toBe(3);
});

it('deletes the candidates when the project goes', function () {
    PullRequestCandidate::factory()->forProject($this->project)->count(3)->create();

    $this->actingAs($this->owner)
        ->delete("/projects/{$this->project->id}")
        ->assertRedirect();

    expect(PullRequestCandidate::query()->count())->toBe(0);
});
