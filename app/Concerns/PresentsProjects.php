<?php

namespace App\Concerns;

use App\Models\Project;
use App\Models\RepositoryConnection;

/**
 * Shapes projects and their repository connection for the front end.
 *
 * Shared because the project page and the repository screen show the same
 * two objects, and they must not drift apart — least of all on what a
 * connection is allowed to expose.
 */
trait PresentsProjects
{
    /**
     * Shape a project for the front end.
     *
     * @return array<string, mixed>
     */
    protected function projectPayload(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'description' => $project->description,
            'created_at' => $project->created_at?->toIso8601String(),
        ];
    }

    /**
     * Shape a repository connection for the front end.
     *
     * The token is never part of this payload. Only its last four characters
     * travel, so the owner can recognize which credential they stored.
     *
     * @return array<string, mixed>|null
     */
    protected function connectionPayload(?RepositoryConnection $connection): ?array
    {
        if ($connection === null) {
            return null;
        }

        return [
            'owner' => $connection->owner,
            'name' => $connection->name,
            'full_name' => $connection->reference()->fullName(),
            'url' => $connection->reference()->url(),
            'is_private' => $connection->is_private,
            'default_branch' => $connection->default_branch,
            'token_last_four' => $connection->token_last_four,
            'token_expires_at' => $connection->token_expires_at?->toIso8601String(),
            'status' => $connection->status->value,
            'error_message' => $connection->last_error_code?->message(),
            'last_checked_at' => $connection->last_checked_at->toIso8601String(),
            'created_at' => $connection->created_at?->toIso8601String(),
        ];
    }
}
