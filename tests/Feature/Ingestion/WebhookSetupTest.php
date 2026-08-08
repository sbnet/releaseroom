<?php

use App\Enums\ConnectionStatus;
use App\Enums\WebhookStatus;
use App\Models\Project;
use App\Models\RepositoryConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGitHub;

uses(FakesGitHub::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->project = Project::factory()->for($this->owner, 'owner')->create();
});

function connect(User $owner, Project $project): void
{
    test()->actingAs($owner)
        ->post("/projects/{$project->id}/repository", [
            'repository_url' => 'https://github.com/acme/platform',
            'token' => 'github_pat_valid_token_value',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();
}

it('creates the hook when the token allows it', function () {
    $this->fakeGitHubWithHook(hookId: 4242);

    connect($this->owner, $this->project);

    $connection = $this->project->repositoryConnection()->firstOrFail();

    expect($connection->webhook_status)->toBe(WebhookStatus::Active)
        ->and($connection->webhook_id)->toBe(4242)
        ->and($connection->managesHook())->toBeTrue();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/hooks')
        && $request->method() === 'POST'
        && $request['events'] === ['pull_request']
        && $request['config']['content_type'] === 'json'
        && $request['config']['url'] === $connection->webhookUrl()
        && $request['config']['secret'] === $connection->webhook_secret);
});

it('still connects when the token cannot manage webhooks', function () {
    $this->fakeGitHubHookFailure(403);

    connect($this->owner, $this->project);

    $connection = $this->project->repositoryConnection()->firstOrFail();

    expect($connection->exists)->toBeTrue()
        ->and($connection->status)->toBe(ConnectionStatus::Connected)
        ->and($connection->webhook_status)->toBe(WebhookStatus::ManualSetupRequired)
        ->and($connection->webhook_id)->toBeNull()
        /* The credentials exist regardless: the manual path needs both. */
        ->and($connection->webhook_token)->toHaveLength(64)
        ->and($connection->webhook_secret)->toHaveLength(64);
});

it('still connects when GitHub refuses the hook for any other reason', function (int $status) {
    $this->fakeGitHubHookFailure($status);

    connect($this->owner, $this->project);

    expect($this->project->repositoryConnection()->firstOrFail())
        ->status->toBe(ConnectionStatus::Connected)
        ->webhook_status->toBe(WebhookStatus::ManualSetupRequired);
})->with([404, 422, 500, 503]);

it('gives every connection its own delivery address', function () {
    $this->fakeGitHubWithHook();
    connect($this->owner, $this->project);

    $other = Project::factory()->for(User::factory(), 'owner')->create();
    $this->fakeGitHubWithHook();
    connect($other->owner, $other);

    $tokens = RepositoryConnection::query()->pluck('webhook_token');

    expect($tokens)->toHaveCount(2)
        ->and($tokens->unique())->toHaveCount(2);
});

/**
 * A hook endpoint that refuses the first attempt and accepts the second.
 *
 * Http::fake() merges stubs rather than replacing them, so a second call
 * would leave the first refusal matching forever. A sequence is the honest
 * way to say "this endpoint answers differently the second time".
 */
function hookRefusedThenCreated(int $hookId): void
{
    Http::fake([
        '*/hooks*' => Http::sequence()
            ->push(['message' => 'Not Found.'], 403)
            ->push(['id' => $hookId], 201),
        '*/pulls*' => Http::response([], 200),
        '*/repos/*' => Http::response(test()->githubRepository(), 200),
    ]);
}

it('activates live delivery on a retry after the token is replaced', function () {
    hookRefusedThenCreated(99);

    connect($this->owner, $this->project);

    $connection = $this->project->repositoryConnection()->firstOrFail();
    expect($connection->webhook_status)->toBe(WebhookStatus::ManualSetupRequired);

    $this->actingAs($this->owner)
        ->post("/projects/{$this->project->id}/repository/webhook")
        ->assertRedirect();

    expect($connection->fresh())
        ->webhook_status->toBe(WebhookStatus::Active)
        ->webhook_id->toBe(99);
});

it('retries the hook automatically when the token is replaced', function () {
    hookRefusedThenCreated(77);

    connect($this->owner, $this->project);

    $this->actingAs($this->owner)
        ->put("/projects/{$this->project->id}/repository", [
            'repository_url' => 'https://github.com/acme/platform',
            'token' => 'github_pat_a_token_that_can_do_webhooks',
        ])
        ->assertSessionHasNoErrors();

    expect($this->project->repositoryConnection()->firstOrFail())
        ->webhook_status->toBe(WebhookStatus::Active)
        ->webhook_id->toBe(77);
});

it('does not call GitHub again when live delivery is already active', function () {
    $connection = RepositoryConnection::factory()
        ->forProject($this->project)
        ->withActiveWebhook()
        ->create(['webhook_id' => 4242]);

    Http::fake();

    $this->actingAs($this->owner)
        ->post("/projects/{$this->project->id}/repository/webhook")
        ->assertRedirect();

    Http::assertNothingSent();

    expect($connection->fresh()->webhook_id)->toBe(4242);
});

it('rotates the signing secret and tells GitHub when it manages the hook', function () {
    $connection = RepositoryConnection::factory()
        ->forProject($this->project)
        ->withActiveWebhook()
        ->create(['webhook_id' => 4242]);

    $before = $connection->webhook_secret;

    Http::fake(['*/hooks/*' => Http::response(['id' => 4242], 200)]);

    $this->actingAs($this->owner)
        ->post("/projects/{$this->project->id}/repository/webhook/secret")
        ->assertRedirect();

    $after = $connection->fresh()->webhook_secret;

    expect($after)->not->toBe($before)->toHaveLength(64);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/hooks/4242')
        && $request->method() === 'PATCH'
        && $request['config']['secret'] === $after);
});

it('rotates the secret without calling GitHub for a hand-made hook', function () {
    $connection = RepositoryConnection::factory()
        ->forProject($this->project)
        ->create();

    $before = $connection->webhook_secret;

    Http::fake();

    $this->actingAs($this->owner)
        ->post("/projects/{$this->project->id}/repository/webhook/secret")
        ->assertRedirect();

    expect($connection->fresh()->webhook_secret)->not->toBe($before);

    Http::assertNothingSent();
});

it('removes the hook when the repository is disconnected', function () {
    RepositoryConnection::factory()
        ->forProject($this->project)
        ->withActiveWebhook()
        ->create(['webhook_id' => 4242]);

    Http::fake(['*/hooks/*' => Http::response([], 204)]);

    $this->actingAs($this->owner)
        ->delete("/projects/{$this->project->id}/repository")
        ->assertRedirect();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/hooks/4242')
        && $request->method() === 'DELETE');

    expect($this->project->repositoryConnection()->exists())->toBeFalse();
});

it('never asks GitHub about a hook it did not create', function () {
    RepositoryConnection::factory()->forProject($this->project)->create();

    Http::fake();

    $this->actingAs($this->owner)
        ->delete("/projects/{$this->project->id}/repository")
        ->assertRedirect();

    Http::assertNothingSent();

    expect($this->project->repositoryConnection()->exists())->toBeFalse();
});

it('disconnects even when GitHub refuses to remove the hook', function () {
    RepositoryConnection::factory()
        ->forProject($this->project)
        ->withActiveWebhook()
        ->create(['webhook_id' => 4242]);

    /* The usual reason for disconnecting: the token is already revoked. */
    Http::fake(['*/hooks/*' => Http::response(['message' => 'Bad credentials.'], 401)]);

    $this->actingAs($this->owner)
        ->delete("/projects/{$this->project->id}/repository")
        ->assertRedirect();

    expect($this->project->repositoryConnection()->exists())->toBeFalse();
});

it('disconnects even when GitHub cannot be reached at all', function () {
    RepositoryConnection::factory()
        ->forProject($this->project)
        ->withActiveWebhook()
        ->create(['webhook_id' => 4242]);

    $this->fakeGitHubUnreachable();

    $this->actingAs($this->owner)
        ->delete("/projects/{$this->project->id}/repository")
        ->assertRedirect();

    expect($this->project->repositoryConnection()->exists())->toBeFalse();
});

it('keeps the signing secret out of the database in plain text', function () {
    $this->fakeGitHubWithHook();
    connect($this->owner, $this->project);

    $connection = $this->project->repositoryConnection()->firstOrFail();

    $stored = DB::table('repository_connections')
        ->where('id', $connection->id)
        ->value('webhook_secret');

    expect($stored)->not->toBe($connection->webhook_secret)
        ->and($stored)->not->toContain($connection->webhook_secret);
});

it('shows the owner the secret and the address they need', function () {
    $this->fakeGitHubHookFailure(403);
    connect($this->owner, $this->project);

    $connection = $this->project->repositoryConnection()->firstOrFail();

    $this->actingAs($this->owner)
        ->get("/projects/{$this->project->id}/repository")
        ->assertInertia(fn ($page) => $page
            ->where('webhook_secret', $connection->webhook_secret)
            ->where('connection.webhook_status', 'manual_setup_required')
            ->where('connection.webhook_url', $connection->webhookUrl())
            ->where('connection.manages_hook', false));
});

it('never sends the GitHub token to the client', function () {
    $this->fakeGitHubWithHook();
    connect($this->owner, $this->project);

    $response = $this->actingAs($this->owner)
        ->get("/projects/{$this->project->id}/repository");

    $response->assertInertia(fn ($page) => $page->missing('connection.token'));

    expect($response->content())->not->toContain('github_pat_valid_token_value');
});

it('refuses hook maintenance from someone who does not own the project', function (string $path) {
    $this->fakeGitHubWithHook();
    connect($this->owner, $this->project);

    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->post("/projects/{$this->project->id}/repository/{$path}")
        ->assertForbidden();
})->with(['webhook', 'webhook/secret']);

it('has no hook to maintain without a connection', function (string $path) {
    $this->actingAs($this->owner)
        ->post("/projects/{$this->project->id}/repository/{$path}")
        ->assertNotFound();
})->with(['webhook', 'webhook/secret']);
