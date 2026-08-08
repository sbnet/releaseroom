<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stubs the GitHub calls the application makes.
 *
 * Pattern order matters: the client returns the first stub that matches, and
 * the broad repository pattern would swallow the hook and pull request ones.
 * Hooks first, then pull requests, then the repository itself.
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
     * A merged pull request, as both the webhook and the list endpoint
     * describe it — the two payloads share a schema.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function githubPullRequest(array $overrides = []): array
    {
        $number = is_int($overrides['number'] ?? null) ? $overrides['number'] : 1;

        return array_merge([
            'id' => 1_000_000 + $number,
            'number' => $number,
            'title' => "Add feature {$number}",
            'body' => "What it does, in a paragraph.\n",
            'user' => [
                'login' => 'octocat',
                'avatar_url' => 'https://avatars.githubusercontent.com/u/583231',
            ],
            'labels' => [],
            'base' => ['ref' => 'main'],
            'merged_at' => now()->subDay()->toIso8601String(),
            'html_url' => "https://github.com/acme/platform/pull/{$number}",
        ], $overrides);
    }

    /**
     * A run of merged pull requests, newest number last.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function githubPullRequests(int $count, int $from = 1): array
    {
        return array_map(
            fn (int $number) => $this->githubPullRequest(['number' => $number]),
            range($from, $from + $count - 1),
        );
    }

    /**
     * Both verification calls succeed, and no hook can be created.
     *
     * The 404 on hooks is the common real case: a token scoped the way the
     * previous spec asked for cannot manage webhooks.
     *
     * @param  array<string, mixed>  $repository
     * @param  array<string, string>  $headers
     */
    protected function fakeGitHub(array $repository = [], array $headers = []): void
    {
        Http::fake([
            '*/hooks*' => Http::response(['message' => 'Not Found.'], 404),
            '*/pulls*' => Http::response([], 200),
            '*/repos/*' => Http::response($this->githubRepository($repository), 200, $headers),
        ]);
    }

    /**
     * Everything succeeds, hook creation included.
     *
     * @param  array<string, mixed>  $repository
     */
    protected function fakeGitHubWithHook(int $hookId = 42, array $repository = []): void
    {
        Http::fake([
            '*/hooks*' => Http::response(['id' => $hookId], 201),
            '*/pulls*' => Http::response([], 200),
            '*/repos/*' => Http::response($this->githubRepository($repository), 200),
        ]);
    }

    /**
     * Hook creation is refused with the given status.
     */
    protected function fakeGitHubHookFailure(int $status): void
    {
        Http::fake([
            '*/hooks*' => Http::response(['message' => 'Refused.'], $status),
            '*/pulls*' => Http::response([], 200),
            '*/repos/*' => Http::response($this->githubRepository(), 200),
        ]);
    }

    /**
     * The pull request list returns these entries, one page.
     *
     * @param  array<int, array<string, mixed>>  $pulls
     */
    protected function fakeGitHubPullList(array $pulls): void
    {
        Http::fake([
            '*/hooks*' => Http::response(['message' => 'Not Found.'], 404),
            '*/pulls*' => Http::response($pulls, 200),
            '*/repos/*' => Http::response($this->githubRepository(), 200),
        ]);
    }

    /**
     * The pull request list returns one page per entry, in order.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $pages
     */
    protected function fakeGitHubPullPages(array $pages): void
    {
        $sequence = Http::sequence();

        foreach ($pages as $page) {
            $sequence->push($page, 200);
        }

        Http::fake([
            '*/hooks*' => Http::response(['message' => 'Not Found.'], 404),
            /* Past the last stubbed page, the repository simply has no more. */
            '*/pulls*' => $sequence->whenEmpty(Http::response([], 200)),
            '*/repos/*' => Http::response($this->githubRepository(), 200),
        ]);
    }

    /**
     * The pull request list is refused.
     *
     * @param  array<string, string>  $headers
     */
    protected function fakeGitHubPullListFailure(int $status, array $headers = []): void
    {
        Http::fake([
            '*/hooks*' => Http::response(['message' => 'Not Found.'], 404),
            '*/pulls*' => Http::response(['message' => 'Refused.'], $status, $headers),
            '*/repos/*' => Http::response($this->githubRepository(), 200),
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
            '*/hooks*' => Http::response(['message' => 'Not Found.'], 404),
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
            '*/hooks*' => Http::response(['message' => 'Not Found.'], 404),
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
