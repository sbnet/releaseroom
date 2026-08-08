<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stubs the two GitHub calls repository verification makes.
 *
 * The pull request pattern is registered first because the repository
 * pattern would swallow it: the client returns the first stub that matches.
 */
trait FakesGitHub
{
    /**
     * The repository payload GitHub returns, with the fields we read.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function githubRepository(array $overrides = []): array
    {
        return array_merge([
            'id' => 123456,
            'full_name' => 'acme/platform',
            'private' => false,
            'default_branch' => 'main',
        ], $overrides);
    }

    /**
     * Both calls succeed.
     *
     * @param  array<string, mixed>  $repository
     * @param  array<string, string>  $headers
     */
    protected function fakeGitHub(array $repository = [], array $headers = []): void
    {
        Http::fake([
            '*/pulls*' => Http::response([], 200),
            '*/repos/*' => Http::response($this->githubRepository($repository), 200, $headers),
        ]);
    }

    /**
     * The repository call fails.
     *
     * @param  array<string, string>  $headers
     */
    protected function fakeGitHubRepositoryFailure(int $status, array $headers = []): void
    {
        Http::fake([
            '*/pulls*' => Http::response([], 200),
            '*/repos/*' => Http::response(['message' => 'Refused.'], $status, $headers),
        ]);
    }

    /**
     * The repository is readable but its pull requests are not.
     *
     * @param  array<string, string>  $headers
     */
    protected function fakeGitHubPullsFailure(int $status, array $headers = []): void
    {
        Http::fake([
            '*/pulls*' => Http::response(['message' => 'Refused.'], $status, $headers),
            '*/repos/*' => Http::response($this->githubRepository(), 200),
        ]);
    }

    /**
     * GitHub cannot be reached at all.
     */
    protected function fakeGitHubUnreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out.'));
    }

    /**
     * Assert no request left the application.
     */
    protected function assertNoGitHubCall(): void
    {
        Http::assertNothingSent();
    }

    /**
     * Assert a request was made against the given repository path.
     */
    protected function assertGitHubCalled(string $path): void
    {
        Http::assertSent(fn (Request $request) => str_contains($request->url(), $path));
    }
}
