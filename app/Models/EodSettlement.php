<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EodSettlement extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;
    use ScopedToStore;
    use ScopedToTenant;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'business_date',
        'status',
        'total_shifts_count',
        'gross_sales',
        'total_discounts',
        'total_returns_refunds',
        'total_voids',
        'net_sales',
        'total_tax',
        'total_service_charge',
        'expected_cash_total',
        'counted_cash_total',
        'cash_discrepancy_total',
        'payment_breakdown_snapshot',
        'closed_by_user_id',
        'closed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d',
            'gross_sales' => 'decimal:2',
            'total_discounts' => 'decimal:2',
            'total_returns_refunds' => 'decimal:2',
            'total_voids' => 'decimal:2',
            'net_sales' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_service_charge' => 'decimal:2',
            'expected_cash_total' => 'decimal:2',
            'counted_cash_total' => 'decimal:2',
            'cash_discrepancy_total' => 'decimal:2',
            'payment_breakdown_snapshot' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
