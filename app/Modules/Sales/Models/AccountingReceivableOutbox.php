<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $store_id
 * @property int $source_document_id
 * @property int $retry_count
 */
class AccountingReceivableOutbox extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToTenant;

    protected $table = 'accounting_receivable_outboxes';

    protected $fillable = [
        'event_id',
        'tenant_id',
        'store_id',
        'event_type',
        'idempotency_key',
        'source_document_type',
        'source_document_id',
        'source_document_number',
        'status',
        'payload',
        'retry_count',
        'last_error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'retry_count' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
