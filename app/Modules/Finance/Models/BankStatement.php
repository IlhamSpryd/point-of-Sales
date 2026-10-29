<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $cash_bank_account_id
 * @property string $statement_number
 * @property CarbonInterface $statement_date
 * @property string $opening_balance
 * @property string $closing_balance
 * @property string|null $imported_file_path
 * @property int|null $imported_by_user_id
 * @property string $status
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class BankStatement extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'bank_statements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'opening_balance' => 'string',
            'closing_balance' => 'string',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'bank_statement_id');
    }

    public function importedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }
}
