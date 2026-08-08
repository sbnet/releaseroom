<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login_on_every_project_route(): void
    {
        $project = Project::factory()->create();

        $this->get(route('projects.index'))->assertRedirect(route('login'));
        $this->get(route('projects.create'))->assertRedirect(route('login'));
        $this->post(route('projects.store'))->assertRedirect(route('login'));
        $this->get(route('projects.show', $project))->assertRedirect(route('login'));
        $this->get(route('projects.edit', $project))->assertRedirect(route('login'));
        $this->patch(route('projects.update', $project))->assertRedirect(route('login'));
        $this->delete(route('projects.destroy', $project))->assertRedirect(route('login'));
    }

    public function test_a_non_owner_is_forbidden_from_showing_a_project(): void
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('projects.show', $project))
            ->assertForbidden();
    }

    public function test_a_non_owner_is_forbidden_from_editing_a_project(): void
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('projects.edit', $project))
            ->assertForbidden();
    }

    public function test_a_non_owner_is_forbidden_from_updating_a_project(): void
    {
        $project = Project::factory()->create(['name' => 'Untouched']);

        $this->actingAs(User::factory()->create())
            ->patch(route('projects.update', $project), [
                'name' => 'Hijacked',
                'slug' => 'hijacked',
            ])
            ->assertForbidden();

        $this->assertSame('Untouched', $project->fresh()->name);
    }

    public function test_a_non_owner_is_forbidden_from_deleting_a_project(): void
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('projects.destroy', $project))
            ->assertForbidden();

        $this->assertNotNull($project->fresh());
    }
}
