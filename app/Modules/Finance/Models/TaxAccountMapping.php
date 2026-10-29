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
 * @property int $tax_code_id
 * @property int $sales_tax_account_id
 * @property int|null $purchase_tax_account_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class TaxAccountMapping extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'tax_account_mappings';

    protected $guarded = ['id'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id');
    }

    public function salesTaxAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'sales_tax_account_id');
    }

    public function purchaseTaxAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'purchase_tax_account_id');
    }
}
