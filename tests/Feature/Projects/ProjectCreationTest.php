<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_creation_form_is_displayed(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('projects.create'));

        $response->assertOk();
    }

    public function test_a_user_can_create_a_project(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Acme Platform',
            'slug' => 'acme-platform',
            'description' => 'Everything Acme ships.',
        ]);

        $project = Project::firstWhere('slug', 'acme-platform');

        $this->assertNotNull($project);
        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('projects.show', $project));

        $this->assertSame('Acme Platform', $project->name);
        $this->assertSame('Everything Acme ships.', $project->description);
    }

    public function test_the_creating_user_becomes_the_owner(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Acme Platform',
            'slug' => 'acme-platform',
        ]);

        $project = Project::firstWhere('slug', 'acme-platform');

        $this->assertNotNull($project);
        $this->assertSame($user->id, $project->user_id);
    }

    public function test_the_description_is_optional(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Acme Platform',
            'slug' => 'acme-platform',
        ]);

        $response->assertSessionHasNoErrors();

        $project = Project::firstWhere('slug', 'acme-platform');

        $this->assertNotNull($project);
        $this->assertNull($project->description);
    }

    public function test_a_blank_description_is_stored_as_null(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('projects.store'), [
                'name' => 'Acme Platform',
                'slug' => 'acme-platform',
                'description' => '   ',
            ])
            ->assertSessionHasNoErrors();

        $project = Project::firstWhere('slug', 'acme-platform');

        $this->assertNotNull($project);
        $this->assertNull($project->description);
    }

    public function test_the_name_and_the_slug_are_required(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('projects.store'), [
                'name' => '',
                'slug' => '',
            ]);

        $response->assertSessionHasErrors(['name', 'slug']);
        $this->assertSame(0, Project::count());
    }

    public function test_a_slug_taken_by_another_user_is_rejected(): void
    {
        Project::factory()->create(['slug' => 'acme-platform']);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('projects.store'), [
                'name' => 'Acme Platform',
                'slug' => 'acme-platform',
            ]);

        $response->assertSessionHasErrors('slug');
        $this->assertSame(1, Project::count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedSlugs(): array
    {
        return [
            'uppercase' => ['Acme'],
            'spaces' => ['acme platform'],
            'underscores' => ['acme_platform'],
            'accents' => ['acmé'],
            'leading hyphen' => ['-acme'],
            'trailing hyphen' => ['acme-'],
            'doubled hyphen' => ['acme--platform'],
            'slash' => ['acme/platform'],
            'too long' => [str_repeat('a', 61)],
        ];
    }

    #[DataProvider('malformedSlugs')]
    public function test_a_slug_that_is_not_url_safe_is_rejected(string $slug): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('projects.store'), [
                'name' => 'Acme Platform',
                'slug' => $slug,
            ]);

        $response->assertSessionHasErrors('slug');
        $this->assertSame(0, Project::count());
    }

    public function test_every_reserved_slug_is_rejected(): void
    {
        $user = User::factory()->create();

        foreach (Project::RESERVED_SLUGS as $slug) {
            $this->actingAs($user)
                ->post(route('projects.store'), ['name' => 'Reserved', 'slug' => $slug])
                ->assertSessionHasErrors('slug');
        }

        $this->assertSame(0, Project::count());
    }

    public function test_a_description_longer_than_the_column_is_rejected(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('projects.store'), [
                'name' => 'Acme Platform',
                'slug' => 'acme-platform',
                'description' => str_repeat('a', 281),
            ]);

        $response->assertSessionHasErrors('description');
        $this->assertSame(0, Project::count());
    }
}
