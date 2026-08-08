<?php

use App\Enums\ConnectionFailure;
use App\Enums\ConnectionStatus;
use App\Enums\IngestionSource;
use App\Jobs\ImportPullRequests;
use App\Models\Project;
use App\Models\PullRequestCandidate;
use App\Models\RepositoryConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesGitHub;

uses(FakesGitHub::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->project = Project::factory()->for($this->owner, 'owner')->create();
    $this->connection = RepositoryConnection::factory()
        ->forProject($this->project)
        ->create(['github_id' => 123456, 'default_branch' => 'main']);
});

/** How many times the pull request list was asked for. */
function pullListCalls(): int
{
    $calls = 0;

    Http::assertSent(function (Request $request) use (&$calls) {
        if (str_contains($request->url(), '/pulls')) {
            $calls++;
        }

        return true;
    });

    return $calls;
}

it('backfills when a repository is connected', function () {
    Queue::fake();

    /* A fresh owner: this one already reads acme/platform elsewhere. */
    $newcomer = User::factory()->create();
    $project = Project::factory()->for($newcomer, 'owner')->create();
    $this->fakeGitHubWithHook();

    $this->actingAs($newcomer)
        ->post("/projects/{$project->id}/repository", [
            'repository_url' => 'https://github.com/acme/platform',
            'token' => 'github_pat_valid_token_value',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    Queue::assertPushed(
        ImportPullRequests::class,
        fn (ImportPullRequests $job) => $job->source === IngestionSource::Backfill,
    );
});

it('stops the backfill at a hundred merged pull requests', function () {
    $this->fakeGitHubPullPages([
        $this->githubPullRequests(100, from: 1),
        $this->githubPullRequests(100, from: 101),
    ]);

    ImportPullRequests::dispatchSync($this->connection, IngestionSource::Backfill);

    expect(PullRequestCandidate::query()->count())->toBe(100)
        ->and(pullListCalls())->toBe(1);
});

it('discards closed pull requests that were never merged', function () {
    $this->fakeGitHubPullList([
        $this->githubPullRequest(['number' => 1]),
        $this->githubPullRequest(['number' => 2, 'merged_at' => null]),
        $this->githubPullRequest(['number' => 3]),
    ]);

    ImportPullRequests::dispatchSync($this->connection, IngestionSource::Backfill);

    expect(PullRequestCandidate::query()->pluck('number')->all())->toBe([1, 3]);
});

it('caps the backfill at five pages of unmerged noise', function () {
    $unmerged = array_map(
        fn (int $number) => $this->githubPullRequest(['number' => $number, 'merged_at' => null]),
        range(1, 100),
    );

    $this->fakeGitHubPullPages(array_fill(0, 8, $unmerged));

    ImportPullRequests::dispatchSync($this->connection, IngestionSource::Backfill);

    expect(PullRequestCandidate::query()->count())->toBe(0)
        ->and(pullListCalls())->toBe(5);
});

it('records the time of a successful import', function () {
    $this->fakeGitHubPullList([$this->githubPullRequest()]);

    expect($this->connection->last_synced_at)->toBeNull();

    ImportPullRequests::dispatchSync($this->connection, IngestionSource::Backfill);

    expect($this->connection->fresh()->last_synced_at)->not->toBeNull();
});

it('syncs only what is missing', function () {
    PullRequestCandidate::factory()->forProject($this->project)->create([
        'github_id' => 1_000_001,
        'number' => 1,
    ]);

    $this->fakeGitHubPullList([
        $this->githubPullRequest(['number' => 1]),
        $this->githubPullRequest(['number' => 2]),
    ]);

    ImportPullRequests::dispatchSync($this->connection, IngestionSource::Sync);

    expect(PullRequestCandidate::query()->count())->toBe(2)
        ->and(PullRequestCandidate::query()->where('number', 2)->firstOrFail()->ingested_via)
        ->toBe(IngestionSource::Sync);
});

it('stops syncing at the first page holding nothing new', function () {
    $page = $this->githubPullRequests(100);

    PullRequestCandidate::factory()
        ->forProject($this->project)
        ->createMany(array_map(fn (int $n) => [
            'github_id' => 1_000_000 + $n,
            'number' => $n,
            'curated_at' => now(),
        ], range(1, 100)));

    $this->fakeGitHubPullPages([$page, $page]);

    ImportPullRequests::dispatchSync($this->connection, IngestionSource::Sync);

    expect(pullListCalls())->toBe(1);
});

it('records a refused sync as a connection failure and keeps what it holds', function () {
    PullRequestCandidate::factory()->forProject($this->project)->count(3)->create();

    $this->fakeGitHubPullListFailure(403, ['x-ratelimit-remaining' => '0']);

    ImportPullRequests::dispatchSync($this->connection, IngestionSource::Sync);

    expect($this->connection->fresh())
        ->status->toBe(ConnectionStatus::Failed)
        ->last_error_code->toBe(ConnectionFailure::RateLimited)
        ->last_synced_at->toBeNull()
        ->and(PullRequestCandidate::query()->count())->toBe(3);
});

it('treats GitHub being unreachable during a sync as a connection failure', function () {
    $this->fakeGitHubUnreachable();

    ImportPullRequests::dispatchSync($this->connection, IngestionSource::Sync);

    expect($this->connection->fresh())
        ->status->toBe(ConnectionStatus::Failed)
        ->last_error_code->toBe(ConnectionFailure::GithubUnavailable);
});

it('asks only for merges into the default branch', function () {
    $this->connection->default_branch = 'release';
    $this->connection->save();

    $this->fakeGitHubPullList([]);

    ImportPullRequests::dispatchSync($this->connection, IngestionSource::Sync);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/pulls')
        && str_contains($request->url(), 'base=release')
        && str_contains($request->url(), 'state=closed'));
});

it('lets an owner trigger a sync', function () {
    Queue::fake();

    $this->actingAs($this->owner)
        ->post("/projects/{$this->project->id}/repository/sync")
        ->assertRedirect();

    Queue::assertPushed(
        ImportPullRequests::class,
        fn (ImportPullRequests $job) => $job->source === IngestionSource::Sync,
    );
});

it('has nothing to sync without a connection', function () {
    $project = Project::factory()->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner)
        ->post("/projects/{$project->id}/repository/sync")
        ->assertNotFound();
});

it('refuses a sync from someone who does not own the project', function () {
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->post("/projects/{$this->project->id}/repository/sync")
        ->assertForbidden();
});

it('throttles syncing like every other call that spends the quota', function () {
    Queue::fake();

    foreach (range(1, 10) as $ignored) {
        $this->actingAs($this->owner)
            ->post("/projects/{$this->project->id}/repository/sync")
            ->assertRedirect();
    }

    $this->actingAs($this->owner)
        ->post("/projects/{$this->project->id}/repository/sync")
        ->assertStatus(429);
});
