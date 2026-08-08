<?php

namespace App\Http\Controllers;

use App\Concerns\PresentsProjects;
use App\Enums\ConnectionFailure;
use App\Enums\ConnectionStatus;
use App\Exceptions\RepositoryVerificationException;
use App\Http\Requests\RepositoryConnectionStoreRequest;
use App\Http\Requests\RepositoryConnectionUpdateRequest;
use App\Models\Project;
use App\Models\RepositoryConnection;
use App\Services\GitHub\RepositoryVerifier;
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
 */
class RepositoryConnectionController extends Controller
{
    use PresentsProjects;

    public function __construct(private readonly RepositoryVerifier $verifier) {}

    /**
     * Show the connect or manage screen.
     */
    public function edit(Project $project): Response
    {
        Gate::authorize('update', $project);

        return Inertia::render('projects/repository/Edit', [
            'project' => $this->projectPayload($project),
            'connection' => $this->connectionPayload($project->repositoryConnection),
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

        $this->applyVerification($connection, $verified);
        $this->persist($project, $connection);

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

        if ($submitted !== null) {
            $connection->setToken($submitted);
        }

        $this->applyVerification($connection, $verified);
        $this->persist($project, $connection);

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
     * Disconnect the repository and destroy the stored token.
     */
    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $this->connection($project)->delete();

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
