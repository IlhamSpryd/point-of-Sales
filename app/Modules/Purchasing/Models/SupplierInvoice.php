<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Enums\InvoiceMatchingStatusEnum;
use App\Enums\InvoiceSettlementStatusEnum;
use App\Enums\SupplierInvoiceStatusEnum;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $supplier_id
 * @property int|null $purchase_order_id
 * @property string $internal_invoice_number
 * @property string $supplier_invoice_number
 * @property string $normalized_supplier_invoice_number
 * @property CarbonInterface $invoice_date
 * @property CarbonInterface $due_date
 * @property string $currency_code
 * @property string $exchange_rate
 * @property string $subtotal_amount
 * @property string $discount_amount
 * @property string $tax_amount
 * @property string $additional_charges_amount
 * @property string $total_amount
 * @property string $debit_note_applied_amount
 * @property string $outstanding_amount
 * @property SupplierInvoiceStatusEnum $status
 * @property InvoiceMatchingStatusEnum $matching_status
 * @property InvoiceSettlementStatusEnum $settlement_status
 * @property CarbonInterface|null $approved_payable_at
 * @property int|null $approved_payable_by_user_id
 * @property string|null $hold_reason
 * @property int|null $hold_approved_by_user_id
 * @property CarbonInterface|null $hold_approved_at
 * @property string|null $dispute_reason
 * @property string|null $notes
 * @property string|null $idempotency_key
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Tenant $tenant
 * @property-read Store $store
 * @property-read Supplier $supplier
 * @property-read PurchaseOrder|null $purchaseOrder
 * @property-read User|null $approvedPayableBy
 * @property-read User|null $holdApprovedBy
 * @property-read Collection<int, SupplierInvoiceLine> $lines
 * @property-read Collection<int, SupplierInvoiceGrnMatch> $grnMatches
 */
class SupplierInvoice extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'supplier_invoices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SupplierInvoiceStatusEnum::class,
            'matching_status' => InvoiceMatchingStatusEnum::class,
            'settlement_status' => InvoiceSettlementStatusEnum::class,
            'invoice_date' => 'date',
            'due_date' => 'date',
            'exchange_rate' => 'string',
            'subtotal_amount' => 'string',
            'discount_amount' => 'string',
            'tax_amount' => 'string',
            'additional_charges_amount' => 'string',
            'total_amount' => 'string',
            'debit_note_applied_amount' => 'string',
            'outstanding_amount' => 'string',
            'approved_payable_at' => 'datetime',
            'hold_approved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Normalisasi nomor faktur pemasok untuk pencegahan duplikasi (AT-09).
     * Menghilangkan spasi, strip, garis miring, dan simbol, serta mengubah ke huruf kapital.
     */
    public static function normalizeInvoiceNumber(string $invoiceNumber): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $invoiceNumber));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function approvedPayableBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_payable_by_user_id');
    }

    public function holdApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hold_approved_by_user_id');
    }

    /**
     * @return HasMany<SupplierInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class, 'supplier_invoice_id')->orderBy('line_number', 'asc');
    }

    /**
     * @return HasMany<SupplierInvoiceGrnMatch, $this>
     */
    public function grnMatches(): HasMany
    {
        return $this->hasMany(SupplierInvoiceGrnMatch::class, 'supplier_invoice_id');
    }

    public function isDraft(): bool
    {
        return $this->status === SupplierInvoiceStatusEnum::Draft;
    }

    public function isSubmitted(): bool
    {
        return $this->status === SupplierInvoiceStatusEnum::Submitted;
    }

    public function isVarianceHold(): bool
    {
        return $this->status === SupplierInvoiceStatusEnum::VarianceHold;
    }

    public function isApprovedPayable(): bool
    {
        return $this->status === SupplierInvoiceStatusEnum::ApprovedPayable;
    }

    public function isDisputed(): bool
    {
        return $this->status === SupplierInvoiceStatusEnum::Disputed;
    }

    public function isCancelled(): bool
    {
        return $this->status === SupplierInvoiceStatusEnum::Cancelled;
    }
}
