<?php

namespace App\Services\GitHub;

use App\Enums\WebhookStatus;
use App\Exceptions\RepositoryVerificationException;
use App\Models\RepositoryConnection;
use Illuminate\Support\Facades\Log;

/**
 * Creates, updates and removes the repository hook on GitHub.
 *
 * Nothing here is allowed to throw. A connection whose hook could not be
 * created is a working connection — backfill and sync need only pull request
 * read access — so a failure downgrades the owner to the manual instructions
 * rather than costing them the connection they just proved works.
 */
class WebhookManager
{
    /** The one event a changelog cares about. */
    private const EVENTS = ['pull_request'];

    public function __construct(private readonly GitHubClient $client) {}

    /**
     * Try to create the hook, and record what happened on the connection.
     *
     * Returns whether GitHub is now delivering.
     */
    public function create(RepositoryConnection $connection): bool
    {
        $reference = $connection->reference();

        try {
            $response = $this->client->post($connection->token, "/repos/{$reference->owner}/{$reference->name}/hooks", [
                'name' => 'web',
                'active' => true,
                'events' => self::EVENTS,
                'config' => $this->config($connection),
            ]);
        } catch (RepositoryVerificationException $exception) {
            return $this->fallBackToManual($connection, $exception->failure->value);
        }

        if ($response->status() !== 201) {
            return $this->fallBackToManual($connection, 'http_'.$response->status());
        }

        $payload = $response->json();
        $hookId = is_array($payload) && is_int($payload['id'] ?? null) ? $payload['id'] : null;

        if ($hookId === null) {
            return $this->fallBackToManual($connection, 'unreadable_response');
        }

        $connection->webhook_id = $hookId;
        $connection->webhook_status = WebhookStatus::Active;
        $connection->save();

        return true;
    }

    /**
     * Push a rotated secret onto a hook we manage.
     *
     * When we do not manage the hook, there is nothing to update: the owner
     * created it by hand and has to paste the new secret themselves.
     */
    public function update(RepositoryConnection $connection): bool
    {
        if (! $connection->managesHook()) {
            return false;
        }

        $reference = $connection->reference();

        try {
            $response = $this->client->patch(
                $connection->token,
                "/repos/{$reference->owner}/{$reference->name}/hooks/{$connection->webhook_id}",
                ['config' => $this->config($connection)],
            );
        } catch (RepositoryVerificationException $exception) {
            return $this->fallBackToManual($connection, $exception->failure->value);
        }

        if ($response->failed()) {
            return $this->fallBackToManual($connection, 'http_'.$response->status());
        }

        return true;
    }

    /**
     * Remove the hook, if we are the ones who put it there.
     *
     * Best effort on purpose. The token may already have been revoked — which
     * is very often exactly why the owner is disconnecting — and blocking a
     * disconnect on GitHub's cooperation would defeat the point of it. A hook
     * left behind starts failing delivery and GitHub disables it on its own.
     */
    public function delete(RepositoryConnection $connection): void
    {
        if (! $connection->managesHook()) {
            return;
        }

        $reference = $connection->reference();

        try {
            $response = $this->client->delete(
                $connection->token,
                "/repos/{$reference->owner}/{$reference->name}/hooks/{$connection->webhook_id}",
            );

            if ($response->failed()) {
                $this->log($connection, 'hook deletion refused', 'http_'.$response->status());
            }
        } catch (RepositoryVerificationException $exception) {
            $this->log($connection, 'hook deletion unreachable', $exception->failure->value);
        }
    }

    /**
     * The hook configuration, which is the same whoever creates it.
     *
     * @return array<string, string>
     */
    private function config(RepositoryConnection $connection): array
    {
        return [
            'url' => $connection->webhookUrl(),
            'content_type' => 'json',
            'secret' => $connection->webhook_secret,
            'insecure_ssl' => '0',
        ];
    }

    /**
     * Leave the owner with the manual instructions and a retry.
     */
    private function fallBackToManual(RepositoryConnection $connection, string $reason): bool
    {
        $connection->webhook_status = WebhookStatus::ManualSetupRequired;
        $connection->save();

        $this->log($connection, 'automatic hook setup failed', $reason);

        return false;
    }

    /**
     * Never log the secret or the token: this line ends up in aggregation.
     */
    private function log(RepositoryConnection $connection, string $message, string $reason): void
    {
        Log::warning("GitHub: {$message}.", [
            'connection_id' => $connection->id,
            'repository' => $connection->reference()->fullName(),
            'reason' => $reason,
        ]);
    }
}
