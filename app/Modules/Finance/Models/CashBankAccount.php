<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\Finance\Enums\CashBankAccountTypeEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $store_id
 * @property string $account_code
 * @property string $account_name
 * @property CashBankAccountTypeEnum $account_type
 * @property int $gl_account_id
 * @property string|null $bank_name
 * @property string|null $bank_account_number
 * @property string|null $bank_account_holder
 * @property string $currency_code
 * @property string $opening_balance
 * @property string $current_balance
 * @property bool $is_active
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class CashBankAccount extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'cash_bank_accounts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'account_type' => CashBankAccountTypeEnum::class,
            'opening_balance' => 'string',
            'current_balance' => 'string',
            'is_active' => 'boolean',
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

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'gl_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CashBankTransaction::class, 'cash_bank_account_id');
    }

    public function statements(): HasMany
    {
        return $this->hasMany(BankStatement::class, 'cash_bank_account_id');
    }
}
