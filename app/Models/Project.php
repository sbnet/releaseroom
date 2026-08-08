<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
}
