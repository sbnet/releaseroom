<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Determine whether the user can view the project.
     */
    public function view(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    /**
     * Determine whether the user can update the project.
     */
    public function update(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    /**
     * Determine whether the user can delete the project.
     */
    public function delete(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    /**
     * A project belongs to exactly one owner for now; shared team access is a
     * separate, later concern.
     */
    private function owns(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }
}
