<?php

declare(strict_types=1);

namespace App\Modules\Integration\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToStore;
use App\Core\Traits\ScopedToTenant;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OfdChannelConfig menyimpan konfigurasi kanal Online Food Delivery (GrabFood, GoFood, ShopeeFood)
 * per cabang dan tenant, mencakup kredensial signing HMAC, komisi, dan persentase markup.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property string $provider
 * @property string $merchant_id
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $signing_secret
 * @property string $commission_rate_pct
 * @property string $price_markup_pct
 * @property int|null $price_list_id
 * @property bool $auto_accept_order
 * @property bool $is_active
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class OfdChannelConfig extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;
    use ScopedToStore;
    use ScopedToTenant;

    protected $table = 'ofd_channel_configs';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'provider',
        'merchant_id',
        'client_id',
        'client_secret',
        'signing_secret',
        'commission_rate_pct',
        'price_markup_pct',
        'price_list_id',
        'auto_accept_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'signing_secret' => 'encrypted',
            'commission_rate_pct' => 'decimal:2',
            'price_markup_pct' => 'decimal:2',
            'auto_accept_order' => 'boolean',
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
}
