<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryStatus;
use App\Enums\WebhookStatus;
use App\Jobs\ProcessWebhookDelivery;
use App\Models\RepositoryConnection;
use App\Models\WebhookDelivery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives GitHub's pull request deliveries.
 *
 * The only route in the application with no session, no CSRF token and no
 * authenticated user. Its authentication is the signature, and its identity
 * is the opaque token in the path — two owners may legitimately have
 * connected the same public repository, so nothing in the payload could tell
 * us whose delivery this is.
 *
 * Nothing is ingested here. GitHub allows about ten seconds and retries what
 * it considers a failure, so the work belongs behind an acknowledgement.
 */
class GitHubWebhookController extends Controller
{
    /**
     * Accept a delivery, or refuse it without leaving a trace.
     */
    public function __invoke(Request $request, string $token): Response
    {
        $connection = RepositoryConnection::query()
            ->where('webhook_token', $token)
            ->first();

        abort_if($connection === null, 404);

        /*
         * Verified before anything is written. An unsigned or wrongly signed
         * request is not evidence of anything, and recording it would let
         * anyone who guesses the address fill the log.
         */
        abort_unless($this->hasValidSignature($request, $connection), 401);

        $deliveryId = $request->header('X-GitHub-Delivery');

        if (! is_string($deliveryId) || $deliveryId === '') {
            abort(400, 'Missing delivery identifier.');
        }

        /* A body that will not decode yields an empty payload, which the job
         * then records as ignored rather than guessing at its meaning. */
        $payload = $request->json()->all();

        $this->markAlive($connection);

        if ($connection->deliveries()->where('delivery_id', $deliveryId)->exists()) {
            return $this->accepted();
        }

        $delivery = new WebhookDelivery;
        $delivery->repository_connection_id = $connection->id;
        $delivery->delivery_id = $deliveryId;
        $delivery->event = (string) ($request->header('X-GitHub-Event') ?? 'unknown');
        $delivery->action = is_string($payload['action'] ?? null) ? $payload['action'] : null;
        $delivery->payload = $payload;
        $delivery->status = DeliveryStatus::Received;

        try {
            $delivery->save();
        } catch (UniqueConstraintViolationException) {
            /* A retry that crossed with its own first attempt. Already ours. */
            return $this->accepted();
        }

        ProcessWebhookDelivery::dispatch($delivery);

        return $this->accepted();
    }

    /**
     * Compare the signature against the raw body.
     *
     * The bytes matter: re-encoding the decoded JSON would reorder keys and
     * change spacing, and every signature would fail.
     */
    private function hasValidSignature(Request $request, RepositoryConnection $connection): bool
    {
        $signature = $request->header('X-Hub-Signature-256');

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $connection->webhook_secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Record that GitHub is reaching us.
     *
     * A signature-valid delivery is proof a hook exists, whoever created it.
     * That is what closes the manual path's one real weakness: we cannot see
     * whether the owner finished pasting the settings, but we can see the
     * first delivery that results from it.
     */
    private function markAlive(RepositoryConnection $connection): void
    {
        $connection->webhook_last_delivery_at = now();
        $connection->webhook_status = WebhookStatus::Active;
        $connection->save();
    }

    /**
     * Acknowledge without a body: GitHub reads the status and nothing else.
     */
    private function accepted(): Response
    {
        return response()->noContent(202);
    }
}
