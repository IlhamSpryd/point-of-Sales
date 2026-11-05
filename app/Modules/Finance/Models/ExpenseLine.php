<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $expense_id
 * @property int $line_number
 * @property string $description
 * @property numeric-string $amount
 * @property int|null $tax_code_id
 * @property numeric-string $tax_amount
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class ExpenseLine extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'expense_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'amount' => 'string',
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
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }

    /**
     * @return BelongsTo<TaxCode, $this>
     */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id');
    }
}
