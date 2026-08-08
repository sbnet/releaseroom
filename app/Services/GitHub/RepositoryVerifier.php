<?php

namespace App\Services\GitHub;

use App\Enums\ConnectionFailure;
use App\Exceptions\RepositoryVerificationException;
use App\Support\GitHub\RepositoryReference;
use App\Support\GitHub\VerifiedRepository;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Http\Client\Response;

/**
 * Proves that a token can read a repository and its pull requests.
 *
 * Two calls, in order: the repository itself, for its identity and metadata,
 * then a single closed pull request, because a token can perfectly well see a
 * repository while carrying none of the permission ingestion will need. A
 * connection is only ever stored once both have answered.
 */
class RepositoryVerifier
{
    public function __construct(private readonly GitHubClient $client) {}

    /**
     * Verify the reference against GitHub with the given token.
     *
     * @throws RepositoryVerificationException
     */
    public function verify(RepositoryReference $reference, string $token): VerifiedRepository
    {
        $repository = $this->client->get($token, "/repos/{$reference->owner}/{$reference->name}");

        if ($repository->failed()) {
            throw new RepositoryVerificationException($this->client->failureFor(
                $repository,
                forbidden: ConnectionFailure::InsufficientAccess,
                notFound: ConnectionFailure::RepositoryNotFound,
            ));
        }

        $pulls = $this->client->get($token, "/repos/{$reference->owner}/{$reference->name}/pulls", [
            'state' => 'closed',
            'per_page' => 1,
        ]);

        if ($pulls->failed()) {
            throw new RepositoryVerificationException($this->client->failureFor(
                $pulls,
                forbidden: ConnectionFailure::MissingPullScope,
                notFound: ConnectionFailure::MissingPullScope,
            ));
        }

        return $this->toVerifiedRepository($repository);
    }

    /**
     * Read the repository payload GitHub returned.
     */
    private function toVerifiedRepository(Response $response): VerifiedRepository
    {
        /** @var array{id?: mixed, full_name?: mixed, private?: mixed, default_branch?: mixed} $payload */
        $payload = $response->json();

        $fullName = is_string($payload['full_name'] ?? null) ? $payload['full_name'] : '';
        [$owner, $name] = array_pad(explode('/', $fullName, 2), 2, '');

        $reference = RepositoryReference::make($owner, $name);

        if (! is_int($payload['id'] ?? null) || $reference === null) {
            throw new RepositoryVerificationException(ConnectionFailure::GithubUnavailable);
        }

        $defaultBranch = is_string($payload['default_branch'] ?? null) && $payload['default_branch'] !== ''
            ? $payload['default_branch']
            : 'main';

        return new VerifiedRepository(
            githubId: $payload['id'],
            reference: $reference,
            isPrivate: (bool) ($payload['private'] ?? false),
            defaultBranch: $defaultBranch,
            tokenExpiresAt: $this->tokenExpiryFrom($response),
        );
    }

    /**
     * GitHub reports a personal access token's expiry in a header, but only
     * when the token has one. Absent or unparseable, we simply do not know.
     */
    private function tokenExpiryFrom(Response $response): ?CarbonImmutable
    {
        $header = $response->header('github-authentication-token-expiration');

        if ($header === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($header);
        } catch (Exception) {
            return null;
        }
    }
}
