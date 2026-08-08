<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\RepositoryConnection;
use App\Services\GitHub\WebhookManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The hook itself, once a repository is connected.
 *
 * Two actions, both recoveries: retry the setup that could not be done
 * automatically at connect time, and rotate a signing secret.
 */
class RepositoryWebhookController extends Controller
{
    public function __construct(private readonly WebhookManager $webhooks) {}

    /**
     * Retry creating the hook on GitHub.
     *
     * The usual path here is a token that has just been replaced with one
     * carrying the Webhooks permission, but a transient failure at connect
     * time lands the owner in the same place.
     */
    public function store(Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $connection = $this->connection($project);

        if ($connection->hasActiveWebhook()) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Live delivery is already active.')]);

            return back();
        }

        $created = $this->webhooks->create($connection);

        Inertia::flash('toast', $created
            ? ['type' => 'success', 'message' => __('Live delivery is now active.')]
            : ['type' => 'error', 'message' => __('GitHub refused to create the webhook. Check the token grants Webhooks access, or set it up by hand.')]);

        return back();
    }

    /**
     * Rotate the signing secret.
     *
     * When we manage the hook, GitHub is told about the new secret and
     * nothing breaks. When the owner created it by hand, only they can
     * update it — so the screen says so rather than pretending otherwise.
     */
    public function update(Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $connection = $this->connection($project);

        $managed = $connection->managesHook();

        $connection->rotateWebhookSecret();
        $connection->save();

        if ($managed && $this->webhooks->update($connection)) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Signing secret rotated.')]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Signing secret rotated. Paste the new one into the webhook on GitHub.'),
        ]);

        return back();
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
}
