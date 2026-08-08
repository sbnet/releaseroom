<?php

namespace Tests\Feature\Repository;

use App\Models\Project;
use App\Models\RepositoryConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepositoryConnectionDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_project_page_shows_the_connected_repository(): void
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
            ->assertSee('acme/platform')
            ->assertSee('https://github.com/acme/platform', escape: false)
            ->assertSee('trunk')
            ->assertSee('connected');
    }

    public function test_the_project_page_offers_to_connect_when_there_is_none(): void
    {
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('No repository connected yet.', escape: false)
            ->assertDontSee('trunk');
    }

    public function test_the_project_index_does_not_carry_connection_details(): void
    {
        $project = Project::factory()->create();
        RepositoryConnection::factory()->forProject($project)->create([
            'owner' => 'acme',
            'name' => 'platform',
        ]);

        $this->actingAs($project->owner)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertDontSee('acme/platform');
    }
}
