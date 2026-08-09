<?php

namespace Tests\Concerns;

use App\Models\RepositoryConnection;
use Illuminate\Testing\TestResponse;

/**
 * Builds and signs deliveries the way GitHub does.
 *
 * The signature is taken over the exact bytes sent, so the body is encoded
 * once here and that same string is both signed and posted. Encoding twice
 * would be the one mistake this helper exists to prevent.
 */
trait SendsWebhooks
{
    /**
     * Post a signed delivery to a connection's address.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function deliver(
        RepositoryConnection $connection,
        array $payload,
        string $event = 'pull_request',
        ?string $deliveryId = null,
        ?string $secret = null,
    ): TestResponse {
        $body = (string) json_encode($payload);

        return $this->call(
            method: 'POST',
            uri: $this->webhookUrl($connection),
            server: $this->headers([
                'X-GitHub-Event' => $event,
                'X-GitHub-Delivery' => $deliveryId ?? (string) fake()->uuid(),
                'X-Hub-Signature-256' => $this->sign($body, $secret ?? $connection->webhook_secret),
            ]),
            content: $body,
        );
    }

    /**
     * Post a delivery with a deliberately wrong or missing signature.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function deliverUnsigned(
        RepositoryConnection $connection,
        array $payload = [],
        ?string $signature = null,
    ): TestResponse {
        $headers = [
            'X-GitHub-Event' => 'pull_request',
            'X-GitHub-Delivery' => (string) fake()->uuid(),
        ];

        if ($signature !== null) {
            $headers['X-Hub-Signature-256'] = $signature;
        }

        return $this->call(
            method: 'POST',
            uri: $this->webhookUrl($connection),
            server: $this->headers($headers),
            content: (string) json_encode($payload),
        );
    }

    /**
     * The delivery a merged pull request produces.
     *
     * @param  array<string, mixed>  $pullRequest
     * @return array<string, mixed>
     */
    protected function mergeEvent(array $pullRequest, int $repositoryId = 123456, string $action = 'closed'): array
    {
        return [
            'action' => $action,
            'repository' => ['id' => $repositoryId, 'full_name' => 'acme/platform'],
            'pull_request' => $pullRequest,
        ];
    }

    /**
     * A fixed signing secret, so a test can sign a body by hand.
     */
    protected function webhookSecret(): string
    {
        return 'a-signing-secret-of-a-perfectly-ordinary-length';
    }

    protected function webhookUrl(RepositoryConnection $connection): string
    {
        return "/webhooks/github/{$connection->webhook_token}";
    }

    protected function sign(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    /**
     * Turn header names into the server variables `call()` expects.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function headers(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $server;
    }
}
