<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * PurchaseDebitNoteApplication
 *
 * Alokasi pemotongan nilai nota debit ke tagihan faktur pemasok.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_debit_note_id
 * @property int $supplier_invoice_id
 * @property string $applied_amount
 * @property Carbon $applied_at
 * @property int $applied_by
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PurchaseDebitNoteApplication extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'purchase_debit_note_applications';

    protected $fillable = [
        'tenant_id',
        'purchase_debit_note_id',
        'supplier_invoice_id',
        'applied_amount',
        'applied_at',
        'applied_by',
        'notes',
    ];

    protected $casts = [
        'applied_amount' => 'string',
        'applied_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<PurchaseDebitNote, $this>
     */
    public function debitNote(): BelongsTo
    {
        return $this->belongsTo(PurchaseDebitNote::class, 'purchase_debit_note_id');
    }

    /**
     * @return BelongsTo<SupplierInvoice, $this>
     */
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
