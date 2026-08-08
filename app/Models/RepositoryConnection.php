<?php

namespace App\Models;

use App\Enums\ConnectionFailure;
use App\Enums\ConnectionStatus;
use App\Support\GitHub\RepositoryReference;
use Carbon\CarbonImmutable;
use Database\Factories\RepositoryConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The GitHub repository a project reads its merged pull requests from.
 *
 * The token is deliberately not fillable and hidden from serialization: it
 * is written through {@see self::setToken()} and never leaves the server.
 *
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property int $github_id
 * @property string $owner
 * @property string $name
 * @property bool $is_private
 * @property string $default_branch
 * @property string $token
 * @property string $token_last_four
 * @property CarbonImmutable|null $token_expires_at
 * @property ConnectionStatus $status
 * @property ConnectionFailure|null $last_error_code
 * @property CarbonImmutable $last_checked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 */
#[Hidden(['token'])]
class RepositoryConnection extends Model
{
    /** @use HasFactory<RepositoryConnectionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_id' => 'integer',
            'is_private' => 'boolean',
            'token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'status' => ConnectionStatus::class,
            'last_error_code' => ConnectionFailure::class,
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * The project this repository feeds.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The user who owns the project, carried here so that "one repository per
     * user" is a database constraint rather than a checked-then-inserted race.
     *
     * @return BelongsTo<User, $this>
     */
    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Store a token, keeping its last four characters for display.
     *
     * The plaintext is encrypted by the cast; the fingerprint is all the
     * owner will ever see of it again.
     */
    public function setToken(string $token): void
    {
        $this->token = $token;
        $this->token_last_four = substr($token, -4);
    }

    /**
     * The repository this connection points at.
     */
    public function reference(): RepositoryReference
    {
        return new RepositoryReference($this->owner, $this->name);
    }

    /**
     * Whether the last verification succeeded.
     */
    public function isConnected(): bool
    {
        return $this->status === ConnectionStatus::Connected;
    }
}
