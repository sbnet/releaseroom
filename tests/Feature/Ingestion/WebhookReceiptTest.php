<?php

use App\Enums\DeliveryStatus;
use App\Enums\WebhookStatus;
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

it('accepts a correctly signed delivery and records it', function () {
    $response = $this->deliver($this->connection, $this->mergeEvent(
        $this->githubPullRequest(['number' => 7]),
    ), deliveryId: 'delivery-1');

    $response->assertStatus(202);

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->delivery_id)->toBe('delivery-1')
        ->and($delivery->event)->toBe('pull_request')
        ->and($delivery->action)->toBe('closed')
        ->and($delivery->status)->toBe(DeliveryStatus::Processed)
        ->and($delivery->repository_connection_id)->toBe($this->connection->id);
});

it('refuses a delivery signed with the wrong secret', function () {
    $body = (string) json_encode($this->mergeEvent($this->githubPullRequest()));

    $this->deliverUnsigned(
        $this->connection,
        $this->mergeEvent($this->githubPullRequest()),
        signature: $this->sign($body, 'not-the-secret'),
    )->assertStatus(401);

    expect(WebhookDelivery::query()->count())->toBe(0)
        ->and(PullRequestCandidate::query()->count())->toBe(0);
});

it('refuses a delivery with no signature at all', function () {
    $this->deliverUnsigned($this->connection, $this->mergeEvent($this->githubPullRequest()))
        ->assertStatus(401);

    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('refuses a delivery whose signature header is malformed', function () {
    $this->deliverUnsigned(
        $this->connection,
        $this->mergeEvent($this->githubPullRequest()),
        signature: 'sha256=nonsense',
    )->assertStatus(401);

    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('returns 404 for an unknown webhook token', function () {
    $body = (string) json_encode([]);

    $this->call(
        method: 'POST',
        uri: '/webhooks/github/'.str_repeat('x', 64),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'ping',
            'HTTP_X_GITHUB_DELIVERY' => 'delivery-x',
            'HTTP_X_HUB_SIGNATURE_256' => $this->sign($body, $this->webhookSecret()),
        ],
        content: $body,
    )->assertNotFound();

    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('refuses a delivery carrying no delivery identifier', function () {
    $body = (string) json_encode($this->mergeEvent($this->githubPullRequest()));

    $this->call(
        method: 'POST',
        uri: $this->webhookUrl($this->connection),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'pull_request',
            'HTTP_X_HUB_SIGNATURE_256' => $this->sign($body, $this->webhookSecret()),
        ],
        content: $body,
    )->assertStatus(400);

    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('treats a redelivery as already handled', function () {
    $payload = $this->mergeEvent($this->githubPullRequest(['number' => 7]));

    $this->deliver($this->connection, $payload, deliveryId: 'delivery-1')->assertStatus(202);
    $this->deliver($this->connection, $payload, deliveryId: 'delivery-1')->assertStatus(202);

    expect(WebhookDelivery::query()->count())->toBe(1)
        ->and(PullRequestCandidate::query()->count())->toBe(1);
});

it('activates a hand-made hook on its first delivery', function () {
    expect($this->connection->webhook_status)->toBe(WebhookStatus::ManualSetupRequired);

    $this->deliver($this->connection, ['zen' => 'Keep it logically awesome.'], event: 'ping')
        ->assertStatus(202);

    $this->connection->refresh();

    expect($this->connection->webhook_status)->toBe(WebhookStatus::Active)
        ->and($this->connection->webhook_last_delivery_at)->not->toBeNull();

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Ignored)
        ->and($delivery->reason)->toBe('Hook confirmed alive.');
});

it('issues no session cookie on the webhook route', function () {
    $response = $this->deliver($this->connection, $this->mergeEvent($this->githubPullRequest()));

    expect($response->headers->getCookies())->toBe([]);
});

it('needs no CSRF token', function () {
    /*
     * The route lives outside the web middleware group, so the token
     * middleware never runs. Enabling it here proves that rather than
     * relying on the testing environment's usual exemption.
     */
    $this->withMiddleware();

    $this->deliver($this->connection, $this->mergeEvent($this->githubPullRequest()))
        ->assertStatus(202);
});

it('records a delivery for a different repository as failed', function () {
    $this->deliver($this->connection, $this->mergeEvent(
        $this->githubPullRequest(),
        repositoryId: 999999,
    ))->assertStatus(202);

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->reason)->toBe('Delivery is for a different repository.')
        ->and(PullRequestCandidate::query()->count())->toBe(0);
});

it('keeps two connections to the same public repository apart', function () {
    $otherOwner = User::factory()->create();
    $otherProject = Project::factory()->for($otherOwner, 'owner')->create();
    $otherConnection = RepositoryConnection::factory()
        ->forProject($otherProject)
        ->withWebhookSecret('a-different-secret-entirely-for-the-other-owner')
        ->create(['github_id' => 123456, 'default_branch' => 'main']);

    $payload = $this->mergeEvent($this->githubPullRequest(['number' => 7]));

    $this->deliver($this->connection, $payload)->assertStatus(202);

    expect($this->project->pullRequestCandidates()->count())->toBe(1)
        ->and($otherProject->pullRequestCandidates()->count())->toBe(0);

    $this->deliver($otherConnection, $payload)->assertStatus(202);

    expect($otherProject->pullRequestCandidates()->count())->toBe(1);
});

it('refuses a delivery signed with the other connection\'s secret', function () {
    $otherOwner = User::factory()->create();
    $otherProject = Project::factory()->for($otherOwner, 'owner')->create();
    RepositoryConnection::factory()
        ->forProject($otherProject)
        ->withWebhookSecret('a-different-secret-entirely-for-the-other-owner')
        ->create(['github_id' => 123456]);

    $this->deliver(
        $this->connection,
        $this->mergeEvent($this->githubPullRequest()),
        secret: 'a-different-secret-entirely-for-the-other-owner',
    )->assertStatus(401);

    expect(WebhookDelivery::query()->count())->toBe(0);
});
