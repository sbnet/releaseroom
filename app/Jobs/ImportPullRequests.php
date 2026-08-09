<?php

namespace App\Jobs;

use App\Enums\ConnectionStatus;
use App\Enums\IngestionSource;
use App\Exceptions\RepositoryVerificationException;
use App\Models\RepositoryConnection;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\PullRequestFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads merged pull requests from the API, for a connection that has them.
 *
 * One job for both pull-based paths: the connect-time backfill and the
 * owner's sync differ in where they stop, not in what they do, and the
 * difference lives in the fetcher.
 */
class ImportPullRequests implements ShouldQueue
{
    use Queueable;

    /**
     * A last resort, and nothing more.
     *
     * Everything this job can anticipate — a refusal, a rate limit, GitHub
     * being unreachable — arrives as a {@see RepositoryVerificationException},
     * because {@see GitHubClient} maps even a transport failure to
     * `github_unavailable`. All of it is caught below and
     * recorded, never retried: each one is already a considered verdict with a
     * sentence for the owner, and "Sync now" is the retry. Behaving the same
     * way on every queue driver, the synchronous one included, matters more
     * here than shaving a minute off a transient outage.
     *
     * So these three attempts only ever cover what the import path does not
     * anticipate at all — a database that went away mid-write, say.
     */
    public int $tries = 3;

    /**
     * Named `repositoryConnection` rather than the obvious `connection`:
     * {@see Queueable} already owns a `$connection` property, and it means
     * the queue's, not GitHub's.
     */
    public function __construct(
        public readonly RepositoryConnection $repositoryConnection,
        public readonly IngestionSource $source,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PullRequestFetcher $fetcher): void
    {
        try {
            $fetcher->fetch($this->repositoryConnection, $this->source);
        } catch (RepositoryVerificationException $exception) {
            /*
             * Whatever was ingested before the refusal stays: a rate limit
             * halfway through a backfill should cost the owner the rest of
             * the page, not the part that worked.
             */
            $this->repositoryConnection->status = ConnectionStatus::Failed;
            $this->repositoryConnection->last_error_code = $exception->failure;
            $this->repositoryConnection->save();

            return;
        }

        /*
         * A successful sync is not a verification: it proves the token can
         * still read pull requests, not that the address still resolves to
         * the repository the owner connected. Clearing a failure stays with
         * "Test connection", which is the call that checks identity.
         */
        $this->repositoryConnection->last_synced_at = now();
        $this->repositoryConnection->save();
    }
}
