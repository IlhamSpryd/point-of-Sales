<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * TR-007: Master Data Mata Uang Multi-Currency.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string $symbol
 * @property int $decimal_places
 * @property bool $is_base
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Currency extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'currencies';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'symbol',
        'decimal_places',
        'is_base',
        'is_active',
    ];

    protected $casts = [
        'decimal_places' => 'integer',
        'is_base' => 'boolean',
        'is_active' => 'boolean',
    ];
}
