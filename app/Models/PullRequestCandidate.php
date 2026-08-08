<?php

namespace App\Models;

use App\Enums\CandidateState;
use App\Enums\IngestionSource;
use Carbon\CarbonImmutable;
use Database\Factories\PullRequestCandidateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merged pull request waiting for the owner's ruling.
 *
 * Ingestion writes these; only a human changes their state. The two are kept
 * apart deliberately — see {@see self::isFrozen()} for the one rule the whole
 * dedup design rests on.
 *
 * @property int $id
 * @property int $project_id
 * @property int $github_id
 * @property int $number
 * @property string $title
 * @property string|null $body
 * @property string|null $author_login
 * @property string|null $author_avatar_url
 * @property list<string> $labels
 * @property string $base_branch
 * @property CarbonImmutable $merged_at
 * @property string $html_url
 * @property CandidateState $state
 * @property CarbonImmutable|null $curated_at
 * @property IngestionSource $ingested_via
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 */
class PullRequestCandidate extends Model
{
    /** @use HasFactory<PullRequestCandidateFactory> */
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
            'number' => 'integer',
            'labels' => 'array',
            'merged_at' => 'datetime',
            'state' => CandidateState::class,
            'curated_at' => 'datetime',
            'ingested_via' => IngestionSource::class,
        ];
    }

    /**
     * The project whose changelog this pull request is a candidate for.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Whether ingestion is still allowed to overwrite the wording.
     *
     * Once the owner has ruled on an entry — dismissed it, or restored it
     * after a dismissal — GitHub does not get to overwrite that ruling. A
     * pending entry nobody has touched keeps tracking upstream, so a typo
     * fixed minutes after the merge arrives without anyone retyping it.
     */
    public function isFrozen(): bool
    {
        return $this->curated_at !== null;
    }

    /**
     * Record that a human has ruled on this entry.
     *
     * Set on the first dismiss or restore and never cleared: restoring is
     * itself a decision, and it is the decision being protected.
     */
    public function markCurated(CandidateState $state): void
    {
        $this->state = $state;
        $this->curated_at ??= now();
    }

    /**
     * Order the list the only way it is ever read.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('merged_at')->orderByDesc('id');
    }
}
