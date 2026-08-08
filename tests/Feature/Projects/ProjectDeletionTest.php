<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_can_delete_their_project(): void
    {
        $project = Project::factory()->create();

        $response = $this->actingAs($project->owner)
            ->delete(route('projects.destroy', $project));

        $response->assertRedirect(route('projects.index'));

        $this->assertNull($project->fresh());
    }

    public function test_the_slug_becomes_available_again_after_deletion(): void
    {
        $project = Project::factory()->create(['slug' => 'acme-platform']);
        $owner = $project->owner;

        $this->actingAs($owner)->delete(route('projects.destroy', $project));

        $this->actingAs($owner)
            ->post(route('projects.store'), [
                'name' => 'Acme Platform',
                'slug' => 'acme-platform',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_deleting_a_user_deletes_their_projects(): void
    {
        $user = User::factory()->create();
        Project::factory()->for($user, 'owner')->create();
        Project::factory()->for($user, 'owner')->create();
        $survivor = Project::factory()->create();

        $user->delete();

        $this->assertSame(1, Project::count());
        $this->assertNotNull($survivor->fresh());
    }
}
