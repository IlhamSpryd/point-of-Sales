<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $employee_id
 * @property string $bank_name
 * @property string|null $bank_code
 * @property string $account_number
 * @property string $account_holder_name
 * @property string|null $branch_name
 * @property bool $is_primary
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Employee $employee
 */
class EmployeeBankAccount extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'employee_bank_accounts';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
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
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Penyamaran Nomor Rekening untuk tampilan UI (UU PDP No. 27/2022).
     * Contoh: BCA •••• 4892
     */
    public function maskedAccountNumber(): string
    {
        $number = $this->account_number;
        if (empty($number)) {
            return '-';
        }

        $last4 = substr($number, -4);

        return "{$this->bank_name} •••• {$last4}";
    }
}
