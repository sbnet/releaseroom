<?php

namespace Tests\Feature\Repository;

use App\Models\Project;
use App\Models\RepositoryConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RepositoryDisconnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_can_disconnect_the_repository(): void
    {
        $project = Project::factory()->create();
        RepositoryConnection::factory()->forProject($project)->create();

        $this->actingAs($project->owner)
            ->delete(route('projects.repository.destroy', $project))
            ->assertRedirect(route('projects.show', $project));

        $this->assertSame(0, RepositoryConnection::count());
    }

    public function test_the_project_returns_to_its_empty_state(): void
    {
        $project = Project::factory()->create();
        RepositoryConnection::factory()->forProject($project)->create();

        $this->actingAs($project->owner)
            ->delete(route('projects.repository.destroy', $project));

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('connection', null));
    }

    public function test_disconnecting_without_a_connection_is_not_found(): void
    {
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->delete(route('projects.repository.destroy', $project))
            ->assertNotFound();
    }

    public function test_deleting_the_project_deletes_its_connection(): void
    {
        $project = Project::factory()->create();
        RepositoryConnection::factory()->forProject($project)->create();

        $this->actingAs($project->owner)
            ->delete(route('projects.destroy', $project))
            ->assertRedirect(route('projects.index'));

        $this->assertSame(0, RepositoryConnection::count());
    }

    public function test_deleting_the_user_deletes_their_connections(): void
    {
        $project = Project::factory()->create();
        RepositoryConnection::factory()->forProject($project)->create();

        $project->owner->delete();

        $this->assertSame(0, Project::count());
        $this->assertSame(0, RepositoryConnection::count());
    }

    public function test_a_non_owner_cannot_disconnect(): void
    {
        $project = Project::factory()->create();
        RepositoryConnection::factory()->forProject($project)->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('projects.repository.destroy', $project))
            ->assertForbidden();

        $this->assertSame(1, RepositoryConnection::count());
    }
}
