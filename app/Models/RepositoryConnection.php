<?php

namespace App\Models;

use App\Enums\ConnectionFailure;
use App\Enums\ConnectionStatus;
use App\Enums\WebhookStatus;
use App\Support\GitHub\RepositoryReference;
use Carbon\CarbonImmutable;
use Database\Factories\RepositoryConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
 * @property string $webhook_token
 * @property string $webhook_secret
 * @property int|null $webhook_id
 * @property WebhookStatus $webhook_status
 * @property CarbonImmutable|null $webhook_last_delivery_at
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read Collection<int, WebhookDelivery> $deliveries
 */
#[Hidden(['token', 'webhook_secret'])]
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
            'webhook_secret' => 'encrypted',
            'webhook_id' => 'integer',
            'webhook_status' => WebhookStatus::class,
            'webhook_last_delivery_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * The deliveries GitHub signed and we accepted for this connection.
     *
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * Give the connection its own delivery address and signing key.
     *
     * Generated for every connection, even when we expect to create the hook
     * ourselves: the manual fallback cannot exist without both, and we only
     * learn that we need the fallback after trying.
     */
    public function generateWebhookCredentials(): void
    {
        $this->webhook_token = Str::random(64);
        $this->webhook_secret = Str::random(64);
        $this->webhook_id = null;
        $this->webhook_status = WebhookStatus::ManualSetupRequired;
    }

    /**
     * Replace the signing key, keeping the delivery address.
     *
     * The address stays put so a hook the owner created by hand keeps
     * reaching us while they paste the new secret in.
     */
    public function rotateWebhookSecret(): void
    {
        $this->webhook_secret = Str::random(64);
    }

    /**
     * The address GitHub posts deliveries to.
     *
     * The token in the path is what identifies the connection. Two owners may
     * legitimately connect the same public repository, so the payload's
     * repository id cannot do that job — the URL has to.
     */
    public function webhookUrl(): string
    {
        return route('webhooks.github', ['token' => $this->webhook_token]);
    }

    /**
     * Whether GitHub is pushing merges at us.
     */
    public function hasActiveWebhook(): bool
    {
        return $this->webhook_status === WebhookStatus::Active;
    }

    /**
     * Whether we created the hook ourselves, and can therefore maintain it.
     */
    public function managesHook(): bool
    {
        return $this->webhook_id !== null;
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
