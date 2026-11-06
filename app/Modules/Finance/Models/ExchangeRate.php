<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TR-007: Master Kurs Valuta Asing Mengambang (Floating Exchange Rates).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $from_currency
 * @property string $to_currency
 * @property numeric-string $rate
 * @property numeric-string $inverse_rate
 * @property \Carbon\Carbon $effective_date
 * @property string $rate_source
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ExchangeRate extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'exchange_rates';

    protected $fillable = [
        'tenant_id',
        'from_currency',
        'to_currency',
        'rate',
        'inverse_rate',
        'effective_date',
        'rate_source',
    ];

    protected $casts = [
        'effective_date' => 'date',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}

