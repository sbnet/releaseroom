<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $owner
 * @property-read RepositoryConnection|null $repositoryConnection
 * @property-read Collection<int, PullRequestCandidate> $pullRequestCandidates
 */
#[Fillable(['name', 'slug', 'description'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Slugs that would collide with an application route once the public
     * release page is served from a top-level address.
     *
     * @var list<string>
     */
    public const RESERVED_SLUGS = [
        'about',
        'admin',
        'api',
        'assets',
        'build',
        'dashboard',
        'docs',
        'email',
        'embed',
        'feed',
        'login',
        'logout',
        'passkey',
        'password',
        'pricing',
        'profile',
        'projects',
        'register',
        'rss',
        'security',
        'settings',
        'storage',
        'two-factor',
        'up',
        'verify',
        'well-known',
    ];

    /**
     * The user this project belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The GitHub repository this project reads its pull requests from, if one
     * has been connected yet.
     *
     * @return HasOne<RepositoryConnection, $this>
     */
    public function repositoryConnection(): HasOne
    {
        return $this->hasOne(RepositoryConnection::class);
    }

    /**
     * The merged pull requests waiting to be turned into a changelog.
     *
     * These hang off the project rather than the connection on purpose:
     * disconnecting a repository revokes a credential, and must not throw
     * away the triage the owner has already done.
     *
     * @return HasMany<PullRequestCandidate, $this>
     */
    public function pullRequestCandidates(): HasMany
    {
        return $this->hasMany(PullRequestCandidate::class);
    }
}
