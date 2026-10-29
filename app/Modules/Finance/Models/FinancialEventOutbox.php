<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\Finance\Enums\OutboxStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $store_id
 * @property string $event_id
 * @property string $event_type
 * @property string $source_module
 * @property string $source_type
 * @property int $source_id
 * @property string|null $source_number
 * @property string $idempotency_key
 * @property array $payload
 * @property OutboxStatusEnum $status
 * @property CarbonInterface $occurred_at
 * @property CarbonInterface|null $processed_at
 * @property int|null $journal_entry_id
 * @property int $retry_count
 * @property CarbonInterface|null $next_retry_at
 * @property string|null $last_error
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class FinancialEventOutbox extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'financial_event_outboxes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxStatusEnum::class,
            'retry_count' => 'integer',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function isPending(): bool
    {
        return $this->status === OutboxStatusEnum::PENDING;
    }
}
