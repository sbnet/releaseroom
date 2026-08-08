<?php

namespace App\Services\GitHub;

use App\Enums\ConnectionFailure;
use App\Exceptions\RepositoryVerificationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Every call the application makes to GitHub goes through here.
 *
 * Verification, backfill, sync and hook management all need the same headers,
 * the same timeouts and — most importantly — the same reading of a refusal.
 * Keeping that reading in one place is what lets a rate limit hit during a
 * backfill say exactly what a rate limit hit during a connect says.
 */
class GitHubClient
{
    /**
     * Perform an authenticated GET.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws RepositoryVerificationException
     */
    public function get(string $token, string $path, array $query = []): Response
    {
        return $this->send(fn (PendingRequest $request) => $request->get($path, $query), $token);
    }

    /**
     * Perform an authenticated POST.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws RepositoryVerificationException
     */
    public function post(string $token, string $path, array $payload): Response
    {
        return $this->send(fn (PendingRequest $request) => $request->post($path, $payload), $token);
    }

    /**
     * Perform an authenticated PATCH.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws RepositoryVerificationException
     */
    public function patch(string $token, string $path, array $payload): Response
    {
        return $this->send(fn (PendingRequest $request) => $request->patch($path, $payload), $token);
    }

    /**
     * Perform an authenticated DELETE.
     *
     * @throws RepositoryVerificationException
     */
    public function delete(string $token, string $path): Response
    {
        return $this->send(fn (PendingRequest $request) => $request->delete($path), $token);
    }

    /**
     * Map a failed response to the reason the owner is shown.
     *
     * The 403 and 404 readings depend on what was being asked for, which is
     * why the caller supplies them: on a repository they mean "wrong address
     * or no access at all", on its pull requests they mean "that one
     * permission is missing".
     */
    public function failureFor(Response $response, ConnectionFailure $forbidden, ConnectionFailure $notFound): ConnectionFailure
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
    public function isRateLimited(Response $response): bool
    {
        if (! in_array($response->status(), [403, 429], true)) {
            return false;
        }

        return $response->header('x-ratelimit-remaining') === '0'
            || $response->header('retry-after') !== '';
    }

    /**
     * Send a request, treating a transport failure as GitHub being down.
     *
     * As far as the owner is concerned the two are the same problem, and
     * neither is anything they typed wrong.
     *
     * @param  callable(PendingRequest): Response  $send
     */
    private function send(callable $send, string $token): Response
    {
        try {
            return $send($this->request($token));
        } catch (ConnectionException) {
            throw new RepositoryVerificationException(ConnectionFailure::GithubUnavailable);
        }
    }

    private function request(string $token): PendingRequest
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
}
