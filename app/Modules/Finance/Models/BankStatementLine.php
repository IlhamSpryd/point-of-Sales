<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Modules\Finance\Enums\BankStatementLineMatchStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $bank_statement_id
 * @property int $cash_bank_account_id
 * @property int $line_number
 * @property CarbonInterface $value_date
 * @property string|null $external_reference
 * @property numeric-string $debit_amount
 * @property numeric-string $credit_amount
 * @property numeric-string $running_balance
 * @property string|null $description
 * @property string $line_fingerprint_hash
 * @property BankStatementLineMatchStatusEnum $match_status
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class BankStatementLine extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'bank_statement_lines';

    protected $fillable = [
        'tenant_id',
        'bank_statement_id',
        'cash_bank_account_id',
        'line_number',
        'value_date',
        'external_reference',
        'debit_amount',
        'credit_amount',
        'running_balance',
        'description',
        'line_fingerprint_hash',
        'match_status',
    ];

    protected function casts(): array
    {
        return [
            'value_date' => 'date',
            'debit_amount' => 'string',
            'credit_amount' => 'string',
            'running_balance' => 'string',
            'match_status' => BankStatementLineMatchStatusEnum::class,
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
     * @return BelongsTo<BankStatement, $this>
     */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }
}
