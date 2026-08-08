<?php

use App\Enums\CandidateState;
use App\Enums\DeliveryStatus;
use App\Enums\IngestionSource;
use App\Jobs\ImportPullRequests;
use App\Models\Project;
use App\Models\PullRequestCandidate;
use App\Models\RepositoryConnection;
use App\Models\User;
use App\Models\WebhookDelivery;
use Tests\Concerns\FakesGitHub;
use Tests\Concerns\SendsWebhooks;

uses(FakesGitHub::class, SendsWebhooks::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->project = Project::factory()->for($this->owner, 'owner')->create();
    $this->connection = RepositoryConnection::factory()
        ->forProject($this->project)
        ->withWebhookSecret($this->webhookSecret())
        ->create(['github_id' => 123456, 'default_branch' => 'main']);
});

it('stores a merged pull request with every field the payload carries', function () {
    $this->deliver($this->connection, $this->mergeEvent($this->githubPullRequest([
        'number' => 42,
        'title' => 'Add the thing',
        'body' => 'It does the thing.',
        'labels' => [['name' => 'feature'], ['name' => 'ui']],
        'merged_at' => '2026-07-01T10:00:00Z',
    ])))->assertStatus(202);

    $candidate = PullRequestCandidate::query()->firstOrFail();

    expect($candidate->project_id)->toBe($this->project->id)
        ->and($candidate->github_id)->toBe(1_000_042)
        ->and($candidate->number)->toBe(42)
        ->and($candidate->title)->toBe('Add the thing')
        ->and($candidate->body)->toBe('It does the thing.')
        ->and($candidate->author_login)->toBe('octocat')
        ->and($candidate->labels)->toBe(['feature', 'ui'])
        ->and($candidate->base_branch)->toBe('main')
        ->and($candidate->merged_at->toIso8601String())->toBe('2026-07-01T10:00:00+00:00')
        ->and($candidate->html_url)->toBe('https://github.com/acme/platform/pull/42')
        ->and($candidate->state)->toBe(CandidateState::Pending)
        ->and($candidate->curated_at)->toBeNull()
        ->and($candidate->ingested_via)->toBe(IngestionSource::Webhook);
});

it('ignores a pull request closed without merging', function () {
    $this->deliver($this->connection, $this->mergeEvent(
        $this->githubPullRequest(['merged_at' => null]),
    ))->assertStatus(202);

    expect(PullRequestCandidate::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->firstOrFail())
        ->status->toBe(DeliveryStatus::Ignored)
        ->reason->toBe('Closed without merging.');
});

it('ignores a merge into a branch other than the default one', function () {
    $this->deliver($this->connection, $this->mergeEvent(
        $this->githubPullRequest(['base' => ['ref' => 'develop']]),
    ))->assertStatus(202);

    expect(PullRequestCandidate::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->firstOrFail())
        ->status->toBe(DeliveryStatus::Ignored)
        ->reason->toBe('Merged into develop, not main.');
});

it('ignores an action that changes nothing', function () {
    $this->deliver($this->connection, $this->mergeEvent(
        $this->githubPullRequest(),
        action: 'synchronize',
    ))->assertStatus(202);

    expect(PullRequestCandidate::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->firstOrFail()->status)->toBe(DeliveryStatus::Ignored);
});

it('ignores an event it never subscribed to', function () {
    $this->deliver($this->connection, ['action' => 'opened'], event: 'issues')
        ->assertStatus(202);

    expect(WebhookDelivery::query()->firstOrFail())
        ->status->toBe(DeliveryStatus::Ignored)
        ->reason->toBe('Not a subscribed event: issues.');
});

it('holds one row for a pull request arriving by webhook, backfill and sync', function () {
    $pull = $this->githubPullRequest(['number' => 7]);

    $this->deliver($this->connection, $this->mergeEvent($pull))->assertStatus(202);

    $this->fakeGitHubPullList([$pull]);
    ImportPullRequests::dispatchSync($this->connection, IngestionSource::Backfill);
    ImportPullRequests::dispatchSync($this->connection, IngestionSource::Sync);

    expect(PullRequestCandidate::query()->count())->toBe(1)
        /* The first path to see it owns the provenance. */
        ->and(PullRequestCandidate::query()->firstOrFail()->ingested_via)
        ->toBe(IngestionSource::Webhook);
});

it('never resurrects a dismissed pull request', function () {
    $pull = $this->githubPullRequest(['number' => 7]);

    $this->deliver($this->connection, $this->mergeEvent($pull))->assertStatus(202);

    $candidate = PullRequestCandidate::query()->firstOrFail();
    $this->actingAs($this->owner)
        ->post("/projects/{$this->project->id}/candidates/{$candidate->id}/dismiss")
        ->assertRedirect();

    /* Everything that could plausibly bring it back, in turn. */
    $this->deliver($this->connection, $this->mergeEvent($pull))->assertStatus(202);

    $this->fakeGitHubPullList([$pull]);
    ImportPullRequests::dispatchSync($this->connection, IngestionSource::Sync);
    ImportPullRequests::dispatchSync($this->connection, IngestionSource::Backfill);

    expect(PullRequestCandidate::query()->count())->toBe(1)
        ->and($candidate->fresh()->state)->toBe(CandidateState::Dismissed);
});

it('picks up a title edited on a pending, untouched pull request', function () {
    $pull = $this->githubPullRequest(['number' => 7, 'title' => 'Original title']);

    $this->deliver($this->connection, $this->mergeEvent($pull))->assertStatus(202);

    $this->deliver($this->connection, $this->mergeEvent(
        $this->githubPullRequest([
            'number' => 7,
            'title' => 'Corrected title',
            'body' => 'Corrected body.',
            'labels' => [['name' => 'fix']],
        ]),
        action: 'edited',
    ))->assertStatus(202);

    expect(PullRequestCandidate::query()->firstOrFail())
        ->title->toBe('Corrected title')
        ->body->toBe('Corrected body.')
        ->labels->toBe(['fix']);
});

it('never overwrites a pull request the owner has ruled on', function () {
    $pull = $this->githubPullRequest(['number' => 7, 'title' => 'Original title']);

    $this->deliver($this->connection, $this->mergeEvent($pull))->assertStatus(202);

    $candidate = PullRequestCandidate::query()->firstOrFail();

    /* Dismiss then restore: ruled on, and back in the pending list. */
    $this->actingAs($this->owner)
        ->post("/projects/{$this->project->id}/candidates/{$candidate->id}/dismiss");
    $this->actingAs($this->owner)
        ->post("/projects/{$this->project->id}/candidates/{$candidate->id}/restore");

    $this->deliver($this->connection, $this->mergeEvent(
        $this->githubPullRequest(['number' => 7, 'title' => 'Upstream rewrite']),
        action: 'edited',
    ))->assertStatus(202);

    expect($candidate->fresh())
        ->title->toBe('Original title')
        ->state->toBe(CandidateState::Pending);
});

it('does not create a candidate from an edit it never ingested', function () {
    $this->deliver($this->connection, $this->mergeEvent(
        $this->githubPullRequest(['number' => 7]),
        action: 'edited',
    ))->assertStatus(202);

    expect(PullRequestCandidate::query()->count())->toBe(0);
});

it('leaves the immutable facts alone on a refresh', function () {
    $this->deliver($this->connection, $this->mergeEvent($this->githubPullRequest([
        'number' => 7,
        'merged_at' => '2026-07-01T10:00:00Z',
    ])))->assertStatus(202);

    $this->deliver($this->connection, $this->mergeEvent(
        $this->githubPullRequest([
            'number' => 7,
            'merged_at' => '2026-01-01T10:00:00Z',
            'html_url' => 'https://github.com/acme/platform/pull/9999',
            'base' => ['ref' => 'main'],
        ]),
        action: 'edited',
    ))->assertStatus(202);

    expect(PullRequestCandidate::query()->firstOrFail())
        ->merged_at->toIso8601String()->toBe('2026-07-01T10:00:00+00:00')
        ->html_url->toBe('https://github.com/acme/platform/pull/7');
});

it('keeps each project\'s copy of a shared pull request independent', function () {
    $otherProject = Project::factory()->for(User::factory(), 'owner')->create();
    $otherConnection = RepositoryConnection::factory()
        ->forProject($otherProject)
        ->withWebhookSecret($this->webhookSecret())
        ->create(['github_id' => 123456, 'default_branch' => 'main']);

    $pull = $this->githubPullRequest(['number' => 7]);

    $this->deliver($this->connection, $this->mergeEvent($pull))->assertStatus(202);
    $this->deliver($otherConnection, $this->mergeEvent($pull))->assertStatus(202);

    expect(PullRequestCandidate::query()->count())->toBe(2);

    $mine = $this->project->pullRequestCandidates()->firstOrFail();
    $this->actingAs($this->owner)
        ->post("/projects/{$this->project->id}/candidates/{$mine->id}/dismiss");

    expect($otherProject->pullRequestCandidates()->firstOrFail()->state)
        ->toBe(CandidateState::Pending);
});
