<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\Finance\Enums\ApPaymentAllocationStatusEnum;
use App\Modules\Purchasing\Models\SupplierInvoice;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $ap_payment_id
 * @property int $supplier_invoice_id
 * @property string $allocated_amount
 * @property string $discount_taken_amount
 * @property ApPaymentAllocationStatusEnum $status
 * @property string $idempotency_key
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class ApPaymentAllocation extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'ap_payment_allocations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ApPaymentAllocationStatusEnum::class,
            'allocated_amount' => 'string',
            'discount_taken_amount' => 'string',
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(ApPayment::class, 'ap_payment_id');
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }
}
