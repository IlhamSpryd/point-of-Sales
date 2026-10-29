<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\Tenant;
use App\Modules\Customers\Enums\StoreCreditStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreCreditAccount extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'current_balance',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => StoreCreditStatusEnum::class,
            'current_balance' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(StoreCreditLedger::class);
    }

    public function isActive(): bool
    {
        return $this->status === StoreCreditStatusEnum::ACTIVE;
    }
}
