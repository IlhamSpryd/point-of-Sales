<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * TR-007: Master Kurs Valuta Asing Mengambang (Floating Exchange Rates).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $from_currency
 * @property string $to_currency
 * @property numeric-string $rate
 * @property numeric-string $inverse_rate
 * @property Carbon $effective_date
 * @property string $rate_source
 * @property Carbon $created_at
 * @property Carbon $updated_at
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
}
