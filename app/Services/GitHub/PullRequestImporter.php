<?php

namespace App\Services\GitHub;

use App\Enums\CandidateState;
use App\Enums\IngestionSource;
use App\Models\Project;
use App\Models\PullRequestCandidate;
use App\Support\GitHub\MergedPullRequest;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Turns a merged pull request into a candidate, exactly once.
 *
 * Three paths reach this class — a webhook delivery, the connect-time
 * backfill, an owner-triggered sync — and they overlap freely by design. What
 * makes that safe is one unique index and the three rules below.
 */
class PullRequestImporter
{
    /**
     * Write or refresh the candidate for this pull request.
     *
     * Returns whether anything was actually written, which is what the
     * sync uses to decide it has caught up and can stop paging.
     */
    public function import(Project $project, MergedPullRequest $pull, IngestionSource $source): bool
    {
        $candidate = $this->find($project, $pull);

        if ($candidate === null) {
            return $this->create($project, $pull, $source);
        }

        return $this->refresh($candidate, $pull);
    }

    /**
     * Refresh a candidate we already hold, and only that.
     *
     * An edit delivered for a pull request we never ingested is not an
     * ingestion trigger: we were not subscribed when it was merged, or it did
     * not qualify then. Creating it here would let an unrelated title change
     * years later drop a surprise entry into the list.
     */
    public function refreshExisting(Project $project, MergedPullRequest $pull): bool
    {
        $candidate = $this->find($project, $pull);

        return $candidate !== null && $this->refresh($candidate, $pull);
    }

    /**
     * The candidate this pull request already has, if any.
     */
    private function find(Project $project, MergedPullRequest $pull): ?PullRequestCandidate
    {
        return PullRequestCandidate::query()
            ->where('project_id', $project->id)
            ->where('github_id', $pull->githubId)
            ->first();
    }

    /**
     * First sighting: a pending candidate nobody has ruled on yet.
     */
    private function create(Project $project, MergedPullRequest $pull, IngestionSource $source): bool
    {
        $candidate = new PullRequestCandidate;
        $candidate->project_id = $project->id;
        $candidate->github_id = $pull->githubId;
        $candidate->number = $pull->number;
        $candidate->base_branch = $pull->baseBranch;
        $candidate->merged_at = $pull->mergedAt;
        $candidate->html_url = $pull->htmlUrl;
        $candidate->state = CandidateState::Pending;
        $candidate->curated_at = null;
        $candidate->ingested_via = $source;

        $this->applyWording($candidate, $pull);

        try {
            $candidate->save();
        } catch (UniqueConstraintViolationException) {
            /*
             * A webhook delivery and a sync can reach the same pull request
             * at the same moment. The unique index is the real guarantee; the
             * lookup above only saves us the exception in the common case.
             * Whoever loses the race refreshes the row the winner just wrote.
             */
            return $this->refreshExisting($project, $pull);
        }

        return true;
    }

    /**
     * Later sighting: track GitHub only while the owner has not ruled.
     *
     * `state` is never written here — that is what makes a dismissal
     * permanent instead of something the next sync undoes. Neither are
     * `number`, `merged_at`, `html_url` or `base_branch`: they describe an
     * event that already happened and cannot change.
     */
    private function refresh(PullRequestCandidate $candidate, MergedPullRequest $pull): bool
    {
        if ($candidate->isFrozen()) {
            return false;
        }

        $this->applyWording($candidate, $pull);

        if (! $candidate->isDirty()) {
            return false;
        }

        $candidate->save();

        return true;
    }

    /**
     * The fields a later edit on GitHub is allowed to change.
     */
    private function applyWording(PullRequestCandidate $candidate, MergedPullRequest $pull): void
    {
        $candidate->title = $pull->title;
        $candidate->body = $pull->body;
        $candidate->author_login = $pull->authorLogin;
        $candidate->author_avatar_url = $pull->authorAvatarUrl;
        $candidate->labels = $pull->labels;
    }
}
