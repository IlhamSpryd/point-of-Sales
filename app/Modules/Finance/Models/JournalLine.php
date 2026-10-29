<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $journal_entry_id
 * @property int $line_number
 * @property int $account_id
 * @property int|null $store_id
 * @property string $debit_amount
 * @property string $credit_amount
 * @property string|null $description
 * @property int|null $customer_id
 * @property int|null $supplier_id
 * @property int|null $tax_code_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class JournalLine extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'journal_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function isDebit(): bool
    {
        return bccomp((string) $this->debit_amount, '0.00', 2) > 0;
    }

    public function isCredit(): bool
    {
        return bccomp((string) $this->credit_amount, '0.00', 2) > 0;
    }

    public function isSingleSided(): bool
    {
        $debit = bccomp((string) $this->debit_amount, '0.00', 2) > 0;
        $credit = bccomp((string) $this->credit_amount, '0.00', 2) > 0;

        return ($debit && ! $credit) || (! $debit && $credit);
    }
}
