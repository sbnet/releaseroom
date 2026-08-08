<?php

namespace App\Http\Controllers;

use App\Concerns\PresentsProjects;
use App\Enums\CandidateState;
use App\Models\Project;
use App\Models\PullRequestCandidate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The list an owner triages before any release exists.
 *
 * The two actions here are the only things in the application that write a
 * candidate's state. Ingestion is deliberately forbidden from it, which is
 * what makes a dismissal survive every later delivery and sync.
 */
class PullRequestCandidateController extends Controller
{
    use PresentsProjects;

    /** Enough to work through in a sitting, short enough to render fast. */
    private const PER_PAGE = 20;

    /**
     * Show the pending or dismissed pull requests of a project.
     */
    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $state = $request->query('state') === CandidateState::Dismissed->value
            ? CandidateState::Dismissed
            : CandidateState::Pending;

        $candidates = $project->pullRequestCandidates()
            ->where('state', $state)
            ->newestFirst()
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (PullRequestCandidate $candidate) => $this->candidatePayload($candidate));

        return Inertia::render('projects/candidates/Index', [
            'project' => $this->projectPayload($project),
            'connection' => $this->connectionPayload($project->repositoryConnection),
            'candidates' => $candidates,
            'state' => $state->value,
            'counts' => $this->counts($project),
        ]);
    }

    /**
     * Rule a pull request out of the changelog.
     */
    public function dismiss(Project $project, PullRequestCandidate $candidate): RedirectResponse
    {
        return $this->rule($project, $candidate, CandidateState::Dismissed, __('Pull request dismissed.'));
    }

    /**
     * Undo a dismissal.
     */
    public function restore(Project $project, PullRequestCandidate $candidate): RedirectResponse
    {
        return $this->rule($project, $candidate, CandidateState::Pending, __('Pull request restored.'));
    }

    /**
     * Record the owner's ruling.
     *
     * Note that restoring leaves `curated_at` set: the owner has looked at
     * this entry and decided to keep it, and that decision is precisely what
     * the freeze rule exists to protect from a later upstream edit.
     */
    private function rule(Project $project, PullRequestCandidate $candidate, CandidateState $state, string $message): RedirectResponse
    {
        Gate::authorize('update', $project);

        /*
         * A candidate belonging to another project is a wrong address, not a
         * refused one: the project in the URL is the authorization boundary,
         * and it has already been cleared.
         */
        abort_if($candidate->project_id !== $project->id, 404);

        $candidate->markCurated($state);
        $candidate->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * How many candidates sit on each side of the triage.
     *
     * @return array<string, int>
     */
    private function counts(Project $project): array
    {
        /** @var array<string, int> $counts */
        $counts = $project->pullRequestCandidates()
            ->getQuery()
            ->selectRaw('state, count(*) as aggregate')
            ->groupBy('state')
            ->pluck('aggregate', 'state')
            ->all();

        return [
            CandidateState::Pending->value => (int) ($counts[CandidateState::Pending->value] ?? 0),
            CandidateState::Dismissed->value => (int) ($counts[CandidateState::Dismissed->value] ?? 0),
        ];
    }
}
