<?php

namespace App\Services\GitHub;

use App\Enums\ConnectionFailure;
use App\Enums\IngestionSource;
use App\Exceptions\RepositoryVerificationException;
use App\Models\RepositoryConnection;
use App\Support\GitHub\MergedPullRequest;

/**
 * Pulls merged pull requests from the API, for the two paths a webhook
 * cannot serve.
 *
 * Backfill covers the history that predates the connection; sync covers the
 * deliveries GitHub gave up on. They read the same endpoint with the same
 * dedup behind them, and differ only in where they stop.
 */
class PullRequestFetcher
{
    private const PAGE_SIZE = 100;

    /**
     * A repository whose closed pull requests are mostly unmerged must not be
     * able to spin. Five pages is five hundred closed pull requests, well
     * past the point where the answer is "run it again".
     */
    private const MAX_PAGES = 5;

    /**
     * Enough to assemble a first release, small enough that the list is
     * triageable on the day it appears.
     */
    private const BACKFILL_LIMIT = 100;

    public function __construct(
        private readonly GitHubClient $client,
        private readonly PullRequestImporter $importer,
    ) {}

    /**
     * Walk the repository's closed pull requests and ingest the merged ones.
     *
     * Returns how many candidates were written or refreshed.
     *
     * @throws RepositoryVerificationException
     */
    public function fetch(RepositoryConnection $connection, IngestionSource $source): int
    {
        $project = $connection->project;
        $branch = $connection->default_branch;

        $written = 0;
        $merged = 0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $items = $this->page($connection, $page);

            if ($items === []) {
                break;
            }

            $wroteOnThisPage = false;

            foreach ($items as $item) {
                $pull = is_array($item) ? MergedPullRequest::fromPayload($item) : null;

                /*
                 * `state=closed` is the closest the endpoint offers: it
                 * returns closed-and-abandoned alongside closed-and-merged,
                 * and only the second kind is a changelog candidate.
                 */
                if ($pull === null || ! $pull->targets($branch)) {
                    continue;
                }

                $merged++;

                if ($this->importer->import($project, $pull, $source)) {
                    $written++;
                    $wroteOnThisPage = true;
                }

                if ($source === IngestionSource::Backfill && $merged >= self::BACKFILL_LIMIT) {
                    return $written;
                }
            }

            /* A short page is the last page. */
            if (count($items) < self::PAGE_SIZE) {
                break;
            }

            /*
             * A gap is usually one delivery wide, so a sync that finds a page
             * it has already seen in full has caught up. Backfill keeps going
             * until its count is met: it is reading history, and history is
             * expected to be full of things we already hold.
             */
            if ($source !== IngestionSource::Backfill && ! $wroteOnThisPage) {
                break;
            }
        }

        return $written;
    }

    /**
     * One page of closed pull requests, newest activity first.
     *
     * The endpoint cannot sort by merge date, so `updated` is the closest
     * approximation: a long-since-merged pull request commented on yesterday
     * sorts ahead of one merged this morning. Accepted for a bounded read of
     * recent history — the alternative, the Search API, trades it for a
     * thirty-requests-per-minute limit and a thousand-result ceiling.
     *
     * @return array<int, mixed>
     *
     * @throws RepositoryVerificationException
     */
    private function page(RepositoryConnection $connection, int $page): array
    {
        $reference = $connection->reference();

        $response = $this->client->get($connection->token, "/repos/{$reference->owner}/{$reference->name}/pulls", [
            'state' => 'closed',
            'base' => $connection->default_branch,
            'sort' => 'updated',
            'direction' => 'desc',
            'per_page' => self::PAGE_SIZE,
            'page' => $page,
        ]);

        if ($response->failed()) {
            throw new RepositoryVerificationException($this->client->failureFor(
                $response,
                forbidden: ConnectionFailure::MissingPullScope,
                notFound: ConnectionFailure::MissingPullScope,
            ));
        }

        $items = $response->json();

        return is_array($items) ? array_values($items) : [];
    }
}
