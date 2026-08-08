<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Enums\IngestionSource;
use App\Models\WebhookDelivery;
use App\Services\GitHub\PullRequestImporter;
use App\Support\GitHub\MergedPullRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Decides what a signed delivery actually meant.
 *
 * Most of what a `pull_request` subscription delivers is not a merge, so
 * `ignored` is the healthy majority outcome here. Every one of them records
 * why, because the question this log exists to answer — why did that pull
 * request never appear? — is almost always answered by one of these lines.
 */
class ProcessWebhookDelivery implements ShouldQueue
{
    use Queueable;

    /** The only actions that can change a candidate. */
    private const ACTIONABLE = ['closed', 'edited'];

    public function __construct(public readonly WebhookDelivery $delivery) {}

    /**
     * Execute the job.
     */
    public function handle(PullRequestImporter $importer): void
    {
        $delivery = $this->delivery;

        if ($delivery->event === 'ping') {
            $delivery->resolve(DeliveryStatus::Ignored, 'Hook confirmed alive.');

            return;
        }

        if ($delivery->event !== 'pull_request') {
            $delivery->resolve(DeliveryStatus::Ignored, "Not a subscribed event: {$delivery->event}.");

            return;
        }

        $connection = $delivery->connection;

        /*
         * The delivery equivalent of `identity_changed`: the hook now points
         * at a repository that is not the one the owner connected. Ingesting
         * it quietly would put another project's pull requests in this
         * changelog.
         */
        $repositoryId = $delivery->payload['repository']['id'] ?? null;

        if (! is_int($repositoryId) || $repositoryId !== $connection->github_id) {
            $delivery->resolve(DeliveryStatus::Failed, 'Delivery is for a different repository.');

            return;
        }

        if (! in_array($delivery->action, self::ACTIONABLE, true)) {
            $delivery->resolve(DeliveryStatus::Ignored, "Action changes nothing: {$delivery->action}.");

            return;
        }

        $payload = $delivery->payload['pull_request'] ?? null;
        $pull = is_array($payload) ? MergedPullRequest::fromPayload($payload) : null;

        if ($pull === null) {
            $delivery->resolve(DeliveryStatus::Ignored, 'Closed without merging.');

            return;
        }

        if (! $pull->targets($connection->default_branch)) {
            $delivery->resolve(
                DeliveryStatus::Ignored,
                "Merged into {$pull->baseBranch}, not {$connection->default_branch}.",
            );

            return;
        }

        /*
         * An edit only ever refreshes something we already hold. See
         * PullRequestImporter::refreshExisting() for why it must not create.
         */
        $written = $delivery->action === 'edited'
            ? $importer->refreshExisting($connection->project, $pull)
            : $importer->import($connection->project, $pull, IngestionSource::Webhook);

        $delivery->resolve(
            DeliveryStatus::Processed,
            $written ? null : 'Already up to date.',
        );
    }
}
