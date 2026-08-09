<?php

namespace App\Http\Controllers;

use App\Concerns\PresentsProjects;
use App\Enums\ConnectionFailure;
use App\Enums\ConnectionStatus;
use App\Enums\IngestionSource;
use App\Exceptions\RepositoryVerificationException;
use App\Http\Requests\RepositoryConnectionStoreRequest;
use App\Http\Requests\RepositoryConnectionUpdateRequest;
use App\Jobs\ImportPullRequests;
use App\Models\Project;
use App\Models\RepositoryConnection;
use App\Services\GitHub\RepositoryVerifier;
use App\Services\GitHub\WebhookManager;
use App\Support\GitHub\RepositoryReference;
use App\Support\GitHub\VerifiedRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The GitHub repository a project reads from.
 *
 * Connecting and updating are all-or-nothing: GitHub is asked first, and
 * nothing is written unless it answers. Re-verification is the one action
 * allowed to record a failure, because by then a working connection already
 * exists and losing it would punish the owner for a revoked token.
 *
 * Hook creation sits outside that guarantee on purpose — see {@see
 * self::setUpWebhook()}.
 */
class RepositoryConnectionController extends Controller
{
    use PresentsProjects;

    public function __construct(
        private readonly RepositoryVerifier $verifier,
        private readonly WebhookManager $webhooks,
    ) {}

    /**
     * Show the connect or manage screen.
     */
    public function edit(Project $project): Response
    {
        Gate::authorize('update', $project);

        $connection = $project->repositoryConnection;

        return Inertia::render('projects/repository/Edit', [
            'project' => $this->projectPayload($project),
            'connection' => $this->connectionPayload($connection),
            /*
             * Unlike the GitHub token, this one is ours: knowing it buys the
             * ability to forge candidates into a list its owner reviews by
             * hand, and the manual setup path cannot exist without showing
             * it. The token grants access to GitHub itself, and never comes
             * back out.
             */
            'webhook_secret' => $connection?->webhook_secret,
            'candidate_count' => $project->pullRequestCandidates()->count(),
        ]);
    }

    /**
     * Connect a repository to a project.
     */
    public function store(RepositoryConnectionStoreRequest $request, Project $project): RedirectResponse
    {
        if ($project->repositoryConnection()->exists()) {
            throw ValidationException::withMessages([
                'repository_url' => __('This project already has a repository connected.'),
            ]);
        }

        /** @var string $token */
        $token = $request->token();

        $verified = $this->verify($request->reference(), $token);

        $this->guardAgainstDuplicate($project, $verified->githubId, exceptId: null);

        $connection = new RepositoryConnection;
        $connection->project_id = $project->id;
        $connection->user_id = $project->user_id;
        $connection->setToken($token);
        $connection->generateWebhookCredentials();

        $this->applyVerification($connection, $verified);
        $this->persist($project, $connection);

        $this->setUpWebhook($connection);

        /*
         * A webhook only ever delivers what is merged after it exists. The
         * backfill is what makes the list useful on the day it is connected
         * rather than on the day of the next merge.
         */
        ImportPullRequests::dispatch($connection, IngestionSource::Backfill);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Repository connected.')]);

        return to_route('projects.show', $project);
    }

    /**
     * Repoint the connection, replace its token, or both.
     */
    public function update(RepositoryConnectionUpdateRequest $request, Project $project): RedirectResponse
    {
        $connection = $this->connection($project);

        /*
         * A blank token means "keep the one you hold". The stored token is
         * then what gets verified, so an update never silently keeps a
         * credential GitHub has since rejected.
         */
        $submitted = $request->token();
        $token = $submitted ?? $connection->token;

        $verified = $this->verify($request->reference(), $token);

        $this->guardAgainstDuplicate($project, $verified->githubId, exceptId: $connection->getKey());

        $repointed = $verified->githubId !== $connection->github_id;

        /*
         * The hook lives on the old repository and would keep delivering its
         * merges, so it has to go — but only once the repointing is certain.
         * `persist()` can still lose a race to the unique index and refuse the
         * whole update, and deleting first would leave that owner with an
         * unchanged connection whose hook no longer exists on GitHub.
         *
         * A clone keeps the old address, the old token and the old hook id,
         * all of which the deletion needs and all of which are about to be
         * overwritten. Eloquent's attributes are a plain array, so the copy is
         * unaffected by what follows.
         */
        $previous = null;

        if ($repointed) {
            $this->guardAgainstRepointing($project);

            $previous = clone $connection;
            $connection->generateWebhookCredentials();
        }

        if ($submitted !== null) {
            $connection->setToken($submitted);
        }

        $this->applyVerification($connection, $verified);
        $this->persist($project, $connection);

        if ($previous !== null) {
            $this->webhooks->delete($previous);
        }

        /*
         * A replaced token is the usual way out of `manual_setup_required`,
         * so the attempt is retried here rather than making the owner find
         * the button afterwards.
         */
        if (! $connection->hasActiveWebhook()) {
            $this->setUpWebhook($connection);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Repository connection updated.')]);

        return to_route('projects.show', $project);
    }

    /**
     * Re-run the verification against the stored token.
     */
    public function check(Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $connection = $this->connection($project);

        try {
            $verified = $this->verifier->verify($connection->reference(), $connection->token);
        } catch (RepositoryVerificationException $exception) {
            return $this->recordFailure($connection, $exception->failure);
        }

        /*
         * The stored numeric id is what makes a rename harmless and a
         * substitution visible: same id, the repository merely moved; a
         * different id, and this address is somebody else's repository now.
         */
        if ($verified->githubId !== $connection->github_id) {
            return $this->recordFailure($connection, ConnectionFailure::IdentityChanged);
        }

        $this->applyVerification($connection, $verified);
        $connection->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection verified.')]);

        return back();
    }

    /**
     * Fill the gaps a webhook left behind.
     *
     * Deliveries get dropped — GitHub gives up after enough failures, and the
     * application can be down during a deploy. Dedup makes this safe to run
     * as often as the owner likes.
     */
    public function sync(Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $connection = $this->connection($project);

        ImportPullRequests::dispatch($connection, IngestionSource::Sync);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Looking for merged pull requests.')]);

        return back();
    }

    /**
     * Disconnect the repository and destroy the stored token.
     */
    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $connection = $this->connection($project);

        /*
         * Best effort, and deliberately before the row goes: the token is
         * about to become unreadable. The project's candidates are untouched
         * — disconnecting revokes a credential, it does not throw away the
         * owner's triage.
         */
        $this->webhooks->delete($connection);

        $connection->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Repository disconnected.')]);

        return to_route('projects.show', $project);
    }

    /**
     * The project's connection, or a 404 when there is none to act on.
     */
    private function connection(Project $project): RepositoryConnection
    {
        $connection = $project->repositoryConnection;

        abort_if($connection === null, 404);

        return $connection;
    }

    /**
     * Ask GitHub, and turn a refusal into an error on the right field.
     */
    private function verify(RepositoryReference $reference, string $token): VerifiedRepository
    {
        try {
            return $this->verifier->verify($reference, $token);
        } catch (RepositoryVerificationException $exception) {
            throw $exception->toValidationException();
        }
    }

    /**
     * Try to have GitHub deliver to us, and carry on either way.
     *
     * This is the one thing in the connect flow allowed to fail quietly. A
     * connection without a hook still works: backfill and sync need only pull
     * request read access, which verification has already proved. Refusing the
     * connection over a permission the previous spec never asked for would
     * punish the owner for our own change of requirements.
     */
    private function setUpWebhook(RepositoryConnection $connection): void
    {
        $this->webhooks->create($connection);
    }

    /**
     * Write the connection, treating a lost race as the refusal the checks
     * above would have raised had they run a moment later.
     *
     * The unique indexes are the actual guarantee; the checks only exist to
     * produce a message worth reading. When two submissions cross — a double
     * click that outruns the disabled button, two open tabs — the database is
     * what refuses, and the owner still deserves that message rather than a
     * 500 after their GitHub quota has already been spent.
     */
    private function persist(Project $project, RepositoryConnection $connection): void
    {
        try {
            $connection->save();
        } catch (UniqueConstraintViolationException) {
            $this->guardAgainstDuplicate($project, $connection->github_id, exceptId: $connection->getKey());

            throw ValidationException::withMessages([
                'repository_url' => __('This project already has a repository connected.'),
            ]);
        }
    }

    /**
     * Refuse a repository the owner already reads from another project.
     *
     * Checked on the numeric id rather than the address, so renaming the
     * repository on GitHub does not open a way around it.
     */
    private function guardAgainstDuplicate(Project $project, int $githubId, ?int $exceptId): void
    {
        $query = RepositoryConnection::query()
            ->where('user_id', $project->user_id)
            ->where('github_id', $githubId)
            ->with('project');

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        $duplicate = $query->first();

        if ($duplicate === null) {
            return;
        }

        throw ValidationException::withMessages([
            'repository_url' => __('This repository is already connected to :project.', [
                'project' => $duplicate->project->name,
            ]),
        ]);
    }

    /**
     * Refuse to change the source of a project that already has entries.
     *
     * Candidates survive a disconnect on purpose, so this is not about losing
     * data — it is about not mixing two repositories' pull requests into one
     * changelog because of a one-character edit to a URL. Disconnecting is
     * the deliberate way to do it, and it says so.
     *
     * A rename or a transfer keeps the same numeric id and never reaches
     * here, which is exactly what that id is stored for.
     */
    private function guardAgainstRepointing(Project $project): void
    {
        if ($project->pullRequestCandidates()->doesntExist()) {
            return;
        }

        throw ValidationException::withMessages([
            'repository_url' => __('This project already has pull requests from another repository. Disconnect it first if you want to change the source.'),
        ]);
    }

    /**
     * Write what GitHub just confirmed onto the connection.
     */
    private function applyVerification(RepositoryConnection $connection, VerifiedRepository $verified): void
    {
        $connection->github_id = $verified->githubId;
        $connection->owner = $verified->reference->owner;
        $connection->name = $verified->reference->name;
        $connection->is_private = $verified->isPrivate;
        $connection->default_branch = $verified->defaultBranch;
        $connection->token_expires_at = $verified->tokenExpiresAt;
        $connection->status = ConnectionStatus::Connected;
        $connection->last_error_code = null;
        $connection->last_checked_at = now();
    }

    /**
     * Mark a connection as failed, keeping it and its token so the owner can
     * fix the cause rather than start over.
     */
    private function recordFailure(RepositoryConnection $connection, ConnectionFailure $failure): RedirectResponse
    {
        $connection->status = ConnectionStatus::Failed;
        $connection->last_error_code = $failure;
        $connection->last_checked_at = now();
        $connection->save();

        Inertia::flash('toast', ['type' => 'error', 'message' => $failure->message()]);

        return back();
    }
}
