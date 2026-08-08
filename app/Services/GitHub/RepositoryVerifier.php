<?php

namespace App\Services\GitHub;

use App\Enums\ConnectionFailure;
use App\Exceptions\RepositoryVerificationException;
use App\Support\GitHub\RepositoryReference;
use App\Support\GitHub\VerifiedRepository;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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
    /**
     * Verify the reference against GitHub with the given token.
     *
     * @throws RepositoryVerificationException
     */
    public function verify(RepositoryReference $reference, string $token): VerifiedRepository
    {
        $repository = $this->get($token, "/repos/{$reference->owner}/{$reference->name}");

        if ($repository->failed()) {
            throw new RepositoryVerificationException($this->failureFor(
                $repository,
                forbidden: ConnectionFailure::InsufficientAccess,
                notFound: ConnectionFailure::RepositoryNotFound,
            ));
        }

        $pulls = $this->get($token, "/repos/{$reference->owner}/{$reference->name}/pulls", [
            'state' => 'closed',
            'per_page' => 1,
        ]);

        if ($pulls->failed()) {
            throw new RepositoryVerificationException($this->failureFor(
                $pulls,
                forbidden: ConnectionFailure::MissingPullScope,
                notFound: ConnectionFailure::MissingPullScope,
            ));
        }

        return $this->toVerifiedRepository($repository);
    }

    /**
     * Perform an authenticated GET against the GitHub API.
     *
     * A transport failure is indistinguishable from GitHub being down as far
     * as the owner is concerned, so both surface as one reason.
     */
    private function get(string $token, string $path, mixed $query = null): Response
    {
        try {
            return $this->client($token)->get($path, $query);
        } catch (ConnectionException) {
            throw new RepositoryVerificationException(ConnectionFailure::GithubUnavailable);
        }
    }

    private function client(string $token): PendingRequest
    {
        /** @var string $baseUrl */
        $baseUrl = config('services.github.api_url');

        /** @var int $timeout */
        $timeout = config('services.github.timeout');

        /** @var int $connectTimeout */
        $connectTimeout = config('services.github.connect_timeout');

        return Http::baseUrl($baseUrl)
            ->withToken($token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'ReleaseRoom',
            ])
            ->connectTimeout($connectTimeout)
            ->timeout($timeout);
    }

    /**
     * Map a failed response to the reason the owner is shown.
     *
     * The 403 and 404 readings differ between the two calls: on the
     * repository they mean "wrong address or no access at all", on the pull
     * requests they mean "that one permission is missing".
     */
    private function failureFor(Response $response, ConnectionFailure $forbidden, ConnectionFailure $notFound): ConnectionFailure
    {
        if ($this->isRateLimited($response)) {
            return ConnectionFailure::RateLimited;
        }

        return match ($response->status()) {
            401 => ConnectionFailure::InvalidToken,
            403 => $forbidden,
            404 => $notFound,
            default => ConnectionFailure::GithubUnavailable,
        };
    }

    /**
     * GitHub answers an exhausted quota with a 403 or a 429 and a zeroed
     * remaining header, which is not the same problem as a missing scope.
     */
    private function isRateLimited(Response $response): bool
    {
        if (! in_array($response->status(), [403, 429], true)) {
            return false;
        }

        return $response->header('x-ratelimit-remaining') === '0'
            || $response->header('retry-after') !== '';
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
