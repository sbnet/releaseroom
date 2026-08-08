<?php

namespace Tests\Feature\Repository;

use App\Models\Project;
use App\Models\RepositoryConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What the screens are handed.
 *
 * These assert on the Inertia props rather than on rendered markup: the
 * server response of a single-page app carries the props and nothing else,
 * so an assertion about visible text would be testing the absence of
 * server-side rendering, not the feature.
 */
class RepositoryConnectionDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_project_page_is_handed_the_connected_repository(): void
    {
        $project = Project::factory()->create();
        RepositoryConnection::factory()->forProject($project)->create([
            'owner' => 'acme',
            'name' => 'platform',
            'is_private' => true,
            'default_branch' => 'trunk',
        ]);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/Show')
                ->where('connection.full_name', 'acme/platform')
                ->where('connection.url', 'https://github.com/acme/platform')
                ->where('connection.is_private', true)
                ->where('connection.default_branch', 'trunk')
                ->where('connection.status', 'connected')
                ->where('connection.error_message', null)
                ->has('connection.last_checked_at')
            );
    }

    public function test_the_project_page_is_handed_nothing_when_there_is_no_connection(): void
    {
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/Show')
                ->where('connection', null)
            );
    }

    public function test_the_connection_payload_never_carries_the_token(): void
    {
        $project = Project::factory()->create();
        $connection = RepositoryConnection::factory()->forProject($project)->make();
        $connection->setToken('github_pat_11ABCDEFG0abcdefghijkl');
        $connection->save();

        foreach (['projects.show', 'projects.repository.edit'] as $route) {
            $this->actingAs($project->owner)
                ->get(route($route, $project))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->missing('connection.token')
                    ->where('connection.token_last_four', 'ijkl')
                );
        }
    }

    public function test_the_repository_screen_is_handed_the_connection(): void
    {
        $project = Project::factory()->create();
        RepositoryConnection::factory()->forProject($project)->create([
            'owner' => 'acme',
            'name' => 'platform',
        ]);

        $this->actingAs($project->owner)
            ->get(route('projects.repository.edit', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/repository/Edit')
                ->where('project.id', $project->id)
                ->where('connection.full_name', 'acme/platform')
            );
    }

    public function test_the_repository_screen_is_handed_nothing_before_a_connection(): void
    {
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->get(route('projects.repository.edit', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/repository/Edit')
                ->where('connection', null)
            );
    }

    public function test_the_project_index_does_not_carry_connection_details(): void
    {
        $project = Project::factory()->create();
        RepositoryConnection::factory()->forProject($project)->create();

        $this->actingAs($project->owner)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/Index')
                ->missing('projects.0.connection')
            );
    }
}
