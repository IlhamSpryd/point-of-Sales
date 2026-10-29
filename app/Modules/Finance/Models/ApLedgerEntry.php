<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\ApEntryTypeEnum;
use App\Modules\Purchasing\Models\PurchaseDebitNote;
use App\Modules\Purchasing\Models\SupplierInvoice;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $supplier_id
 * @property int|null $supplier_invoice_id
 * @property int|null $ap_payment_id
 * @property int|null $purchase_debit_note_id
 * @property int|null $journal_entry_id
 * @property string $entry_number
 * @property CarbonInterface $effective_date
 * @property ApEntryTypeEnum $entry_type
 * @property string $debit_amount
 * @property string $credit_amount
 * @property string $running_balance
 * @property string $idempotency_key
 * @property string|null $description
 * @property int|null $created_by_user_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class ApLedgerEntry extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'ap_ledger_entries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entry_type' => ApEntryTypeEnum::class,
            'effective_date' => 'date',
            'debit_amount' => 'string',
            'credit_amount' => 'string',
            'running_balance' => 'string',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(ApPayment::class, 'ap_payment_id');
    }

    public function debitNote(): BelongsTo
    {
        return $this->belongsTo(PurchaseDebitNote::class, 'purchase_debit_note_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
