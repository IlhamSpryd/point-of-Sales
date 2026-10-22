<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $event_id
 * @property int $tenant_id
 * @property int|null $store_id
 * @property string $event_type
 * @property string $idempotency_key
 * @property string $source_document_type
 * @property int $source_document_id
 * @property string $source_document_number
 * @property string $status
 * @property array<string, mixed> $payload
 * @property int $retry_count
 * @property string|null $last_error
 * @property CarbonInterface|null $processed_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Tenant $tenant
 */
class AccountingPayableOutbox extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'accounting_payable_outbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'retry_count' => 'integer',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed_by_m7';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
