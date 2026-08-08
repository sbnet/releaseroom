<?php

namespace App\Http\Controllers;

use App\Concerns\PresentsProjects;
use App\Http\Requests\ProjectStoreRequest;
use App\Http\Requests\ProjectUpdateRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    use PresentsProjects;

    /**
     * List the projects owned by the current user.
     */
    public function index(Request $request): Response
    {
        $projects = $this->user($request)
            ->projects()
            ->latest()
            ->get()
            ->map(fn (Project $project) => $this->projectPayload($project))
            ->all();

        return Inertia::render('projects/Index', [
            'projects' => $projects,
        ]);
    }

    /**
     * Show the form to create a new project.
     */
    public function create(): Response
    {
        return Inertia::render('projects/Create');
    }

    /**
     * Store a new project owned by the current user.
     */
    public function store(ProjectStoreRequest $request): RedirectResponse
    {
        $project = $this->user($request)->projects()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project created.')]);

        return to_route('projects.show', $project);
    }

    /**
     * Show a single project.
     */
    public function show(Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Show', [
            'project' => $this->projectPayload($project),
            'connection' => $this->connectionPayload($project->repositoryConnection),
        ]);
    }

    /**
     * Show the form to edit a project.
     */
    public function edit(Project $project): Response
    {
        Gate::authorize('update', $project);

        return Inertia::render('projects/Edit', [
            'project' => $this->projectPayload($project),
        ]);
    }

    /**
     * Update a project's name, slug and description.
     */
    public function update(ProjectUpdateRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project updated.')]);

        return to_route('projects.show', $project);
    }

    /**
     * Permanently delete a project.
     */
    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $project->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project deleted.')]);

        return to_route('projects.index');
    }

    /**
     * The authenticated user driving the request.
     */
    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
