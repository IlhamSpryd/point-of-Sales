<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Modules\Finance\Enums\TaxDirectionEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $tax_code_id
 * @property int|null $tax_rate_id
 * @property int|null $journal_entry_id
 * @property int|null $journal_line_id
 * @property string $entry_number
 * @property CarbonInterface $transaction_date
 * @property TaxDirectionEnum $tax_direction
 * @property numeric-string $tax_base_amount
 * @property numeric-string $applied_rate
 * @property numeric-string $tax_amount
 * @property string|null $counterparty_type
 * @property int|null $counterparty_id
 * @property string|null $counterparty_name
 * @property string|null $counterparty_tax_id
 * @property string|null $invoice_reference
 * @property string|null $tax_invoice_number
 * @property string|null $notes
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class TaxLedgerEntry extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'tax_ledger_entries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'tax_direction' => TaxDirectionEnum::class,
            'tax_base_amount' => 'string',
            'applied_rate' => 'string',
            'tax_amount' => 'string',
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
     * @return BelongsTo<TaxCode, $this>
     */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id');
    }

    /**
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<JournalLine, $this>
     */
    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class, 'journal_line_id');
    }
}
