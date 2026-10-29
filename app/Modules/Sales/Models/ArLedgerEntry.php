<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Sales\Enums\ArLedgerEntryTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ArLedgerEntry extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;
    use ScopedToStore;
    use ScopedToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'customer_id',
        'customer_invoice_id',
        'entry_date',
        'entry_type',
        'reference_type',
        'reference_id',
        'reference_number',
        'debit_amount',
        'credit_amount',
        'running_balance_snapshot',
        'idempotency_key',
        'actor_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'datetime',
            'entry_type' => ArLedgerEntryTypeEnum::class,
            'debit_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'running_balance_snapshot' => 'decimal:2',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
