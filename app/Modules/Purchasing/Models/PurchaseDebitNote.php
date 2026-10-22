<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Enums\DebitNoteStatusEnum;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * PurchaseDebitNote
 *
 * Dokumen komersial nota debit pembelian / pengurang tagihan hutang pemasok.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $supplier_id
 * @property int|null $purchase_return_id
 * @property string $debit_note_number
 * @property Carbon $debit_note_date
 * @property string $currency_code
 * @property string $subtotal_amount
 * @property string $tax_amount
 * @property string $total_amount
 * @property string $applied_amount
 * @property string $remaining_amount
 * @property DebitNoteStatusEnum $status
 * @property string|null $supplier_credit_note_number
 * @property string|null $reason
 * @property int $created_by
 * @property Carbon|null $issued_at
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PurchaseDebitNote extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'purchase_debit_notes';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'supplier_id',
        'purchase_return_id',
        'debit_note_number',
        'debit_note_date',
        'currency_code',
        'subtotal_amount',
        'tax_amount',
        'total_amount',
        'applied_amount',
        'remaining_amount',
        'status',
        'supplier_credit_note_number',
        'reason',
        'created_by',
        'issued_at',
        'idempotency_key',
    ];

    protected $casts = [
        'status' => DebitNoteStatusEnum::class,
        'debit_note_date' => 'date',
        'subtotal_amount' => 'string',
        'tax_amount' => 'string',
        'total_amount' => 'string',
        'applied_amount' => 'string',
        'remaining_amount' => 'string',
        'issued_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<PurchaseReturn, $this>
     */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<PurchaseDebitNoteApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(PurchaseDebitNoteApplication::class, 'purchase_debit_note_id');
    }
}
