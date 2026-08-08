<?php

use App\Enums\CandidateState;
use App\Models\Project;
use App\Models\PullRequestCandidate;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->project = Project::factory()->for($this->owner, 'owner')->create();
    $this->candidate = PullRequestCandidate::factory()->forProject($this->project)->create();
});

it('refuses the list to someone who does not own the project', function () {
    $this->actingAs(User::factory()->create())
        ->get("/projects/{$this->project->id}/candidates")
        ->assertForbidden();
});

it('refuses a ruling from someone who does not own the project', function (string $action) {
    $this->actingAs(User::factory()->create())
        ->post("/projects/{$this->project->id}/candidates/{$this->candidate->id}/{$action}")
        ->assertForbidden();

    expect($this->candidate->fresh())
        ->state->toBe(CandidateState::Pending)
        ->curated_at->toBeNull();
})->with(['dismiss', 'restore']);

it('sends a guest to log in', function () {
    $this->get("/projects/{$this->project->id}/candidates")->assertRedirect('/login');

    $this->post("/projects/{$this->project->id}/candidates/{$this->candidate->id}/dismiss")
        ->assertRedirect('/login');
});

it('treats a candidate from another project as a wrong address', function (string $action) {
    $otherProject = Project::factory()->for($this->owner, 'owner')->create();
    $stranger = PullRequestCandidate::factory()->forProject($otherProject)->create();

    $this->actingAs($this->owner)
        ->post("/projects/{$this->project->id}/candidates/{$stranger->id}/{$action}")
        ->assertNotFound();

    expect($stranger->fresh()->curated_at)->toBeNull();
})->with(['dismiss', 'restore']);

it('shows an empty list for a project with no candidates', function () {
    $empty = Project::factory()->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner)
        ->get("/projects/{$empty->id}/candidates")
        ->assertInertia(fn ($page) => $page
            ->has('candidates.data', 0)
            ->where('counts.pending', 0)
            ->where('counts.dismissed', 0)
            ->where('connection', null));
});
