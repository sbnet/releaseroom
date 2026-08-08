<?php

namespace App\Concerns;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProjectValidationRules
{
    /**
     * Normalize the payload before validating.
     *
     * An untouched textarea posts an empty string; a project without a
     * description should hold null, not "".
     */
    protected function prepareForValidation(): void
    {
        $description = $this->input('description');

        if (is_string($description) && trim($description) === '') {
            $this->merge(['description' => null]);
        }
    }

    /**
     * Get the validation rules used to validate projects.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function projectRules(?int $projectId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => $this->slugRules($projectId),
            'description' => ['nullable', 'string', 'max:280'],
        ];
    }

    /**
     * Get the validation rules used to validate project slugs.
     *
     * The slug is the future address of the public release page: it has to be
     * URL-safe, unique application-wide, and free of collisions with the
     * application's own routes.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function slugRules(?int $projectId = null): array
    {
        return [
            'required',
            'string',
            'max:60',
            'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            Rule::notIn(Project::RESERVED_SLUGS),
            $projectId === null
                ? Rule::unique(Project::class)
                : Rule::unique(Project::class)->ignore($projectId),
        ];
    }

    /**
     * Get the validation messages for the project rules.
     *
     * @return array<string, string>
     */
    protected function projectMessages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers and single hyphens.',
            'slug.not_in' => 'This slug is reserved by the application.',
            'slug.unique' => 'This slug is already taken.',
        ];
    }
}
