<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What GitHub sent, and what we did with it.
 *
 * The log exists for one question asked after the fact: why did that pull
 * request never appear? Without it the answer is a shrug, because a webhook
 * leaves no trace anywhere else.
 *
 * @property int $id
 * @property int $repository_connection_id
 * @property string $delivery_id
 * @property string $event
 * @property string|null $action
 * @property array<string, mixed> $payload
 * @property DeliveryStatus $status
 * @property string|null $reason
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read RepositoryConnection $connection
 */
class WebhookDelivery extends Model
{
    use MassPrunable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => DeliveryStatus::class,
            'processed_at' => 'datetime',
        ];
    }

    /**
     * The connection this delivery arrived for.
     *
     * @return BelongsTo<RepositoryConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(RepositoryConnection::class, 'repository_connection_id');
    }

    /**
     * Long enough to investigate an incident, short enough that the table
     * does not quietly become an archive of every payload GitHub ever sent.
     *
     * Nothing schedules `model:prune` yet; that waits on the deployment
     * growing a scheduler.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<=', now()->subDays(30));
    }

    /**
     * Close the delivery with a verdict.
     */
    public function resolve(DeliveryStatus $status, ?string $reason = null): void
    {
        $this->status = $status;
        $this->reason = $reason;
        $this->processed_at = now();
        $this->save();
    }
}
