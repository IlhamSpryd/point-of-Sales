<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Modules\Finance\Enums\TaxCalculationTypeEnum;
use App\Modules\Finance\Enums\TaxTypeEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property TaxTypeEnum $tax_type
 * @property TaxCalculationTypeEnum $calculation_type
 * @property bool $is_active
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class TaxCode extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'tax_codes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tax_type' => TaxTypeEnum::class,
            'calculation_type' => TaxCalculationTypeEnum::class,
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class, 'tax_code_id');
    }

    public function accountMapping(): HasOne
    {
        return $this->hasOne(TaxAccountMapping::class, 'tax_code_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(TaxLedgerEntry::class, 'tax_code_id');
    }
}
