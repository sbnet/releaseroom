<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_can_open_the_edit_form(): void
    {
        $project = Project::factory()->create();

        $response = $this->actingAs($project->owner)
            ->get(route('projects.edit', $project));

        $response->assertOk();
    }

    public function test_the_owner_can_update_the_name_the_slug_and_the_description(): void
    {
        $project = Project::factory()->create([
            'name' => 'Acme Platform',
            'slug' => 'acme-platform',
            'description' => 'Old description.',
        ]);

        $response = $this->actingAs($project->owner)
            ->patch(route('projects.update', $project), [
                'name' => 'Acme Cloud',
                'slug' => 'acme-cloud',
                'description' => 'New description.',
            ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('projects.show', $project));

        $project->refresh();

        $this->assertSame('Acme Cloud', $project->name);
        $this->assertSame('acme-cloud', $project->slug);
        $this->assertSame('New description.', $project->description);
    }

    public function test_keeping_the_projects_own_slug_is_accepted(): void
    {
        $project = Project::factory()->create(['slug' => 'acme-platform']);

        $response = $this->actingAs($project->owner)
            ->patch(route('projects.update', $project), [
                'name' => 'Renamed',
                'slug' => 'acme-platform',
            ]);

        $response->assertSessionHasNoErrors();

        $project->refresh();

        $this->assertSame('Renamed', $project->name);
        $this->assertSame('acme-platform', $project->slug);
    }

    public function test_taking_another_projects_slug_is_rejected(): void
    {
        Project::factory()->create(['slug' => 'taken']);
        $project = Project::factory()->create(['slug' => 'acme-platform']);

        $response = $this->actingAs($project->owner)
            ->patch(route('projects.update', $project), [
                'name' => $project->name,
                'slug' => 'taken',
            ]);

        $response->assertSessionHasErrors('slug');

        $this->assertSame('acme-platform', $project->fresh()->slug);
    }

    public function test_a_malformed_slug_is_rejected_on_update(): void
    {
        $project = Project::factory()->create(['slug' => 'acme-platform']);

        $response = $this->actingAs($project->owner)
            ->patch(route('projects.update', $project), [
                'name' => $project->name,
                'slug' => 'Acme Platform',
            ]);

        $response->assertSessionHasErrors('slug');

        $this->assertSame('acme-platform', $project->fresh()->slug);
    }

    public function test_a_reserved_slug_is_rejected_on_update(): void
    {
        $project = Project::factory()->create(['slug' => 'acme-platform']);

        $response = $this->actingAs($project->owner)
            ->patch(route('projects.update', $project), [
                'name' => $project->name,
                'slug' => 'dashboard',
            ]);

        $response->assertSessionHasErrors('slug');

        $this->assertSame('acme-platform', $project->fresh()->slug);
    }

    public function test_the_owner_cannot_reassign_the_project_to_someone_else(): void
    {
        $project = Project::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($project->owner)
            ->patch(route('projects.update', $project), [
                'name' => $project->name,
                'slug' => $project->slug,
                'user_id' => $other->id,
            ]);

        $this->assertSame($project->user_id, $project->fresh()->user_id);
    }
}
