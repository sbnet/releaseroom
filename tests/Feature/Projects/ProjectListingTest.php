<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_index_lists_only_the_acting_users_projects(): void
    {
        $user = User::factory()->create();
        $own = Project::factory()->for($user, 'owner')->create(['slug' => 'mine']);
        Project::factory()->create(['slug' => 'someone-else']);

        $response = $this->actingAs($user)->get(route('projects.index'));

        $response->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('projects/Index')
                ->has('projects', 1)
                ->where('projects.0.id', $own->id)
                ->where('projects.0.slug', 'mine')
        );
    }

    public function test_the_index_is_empty_for_a_user_without_projects(): void
    {
        Project::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('projects.index'));

        $response->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('projects/Index')
                ->has('projects', 0)
        );
    }

    public function test_the_index_lists_the_most_recent_projects_first(): void
    {
        $user = User::factory()->create();

        $older = Project::factory()->for($user, 'owner')->create(['slug' => 'older']);
        $older->forceFill(['created_at' => now()->subDay()])->save();

        Project::factory()->for($user, 'owner')->create(['slug' => 'newer']);

        $response = $this->actingAs($user)->get(route('projects.index'));

        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('projects.0.slug', 'newer')
                ->where('projects.1.slug', 'older')
        );
    }

    public function test_the_owner_can_open_their_project(): void
    {
        $project = Project::factory()->create();

        $response = $this->actingAs($project->owner)
            ->get(route('projects.show', $project));

        $response->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('projects/Show')
                ->where('project.id', $project->id)
                ->where('project.slug', $project->slug)
        );
    }
}
