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
 * @property string $debit_amount
 * @property string $credit_amount
 * @property string $running_balance
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

    protected $guarded = ['id'];

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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }
}
