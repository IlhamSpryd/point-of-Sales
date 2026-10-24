<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Models\CashDrawerMovement;
use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Scopes\StoreScope;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Model Device: Merepresentasikan satu terminal fisik perangkat kasir (POS),
 * KDS, atau tablet pelayan yang terikat mutlak ke satu Store fisik.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property string $device_code
 * @property string $device_name
 * @property string $device_type
 * @property string|null $pairing_token
 * @property Carbon|null $pairing_token_expires_at
 * @property string|null $device_fingerprint
 * @property bool $is_active
 * @property string $status
 * @property Carbon|null $last_seen_at
 * @property string|null $ip_address
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Device extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;
    use ScopedToStore;
    use ScopedToTenant;
    use SoftDeletes;

    public const TYPE_POS_TERMINAL = 'pos_terminal';

    public const TYPE_KDS_DISPLAY = 'kds_display';

    public const TYPE_WAITER_TABLET = 'waiter_tablet';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAIRED = 'paired';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'device_code',
        'device_name',
        'device_type',
        'pairing_token',
        'pairing_token_expires_at',
        'device_fingerprint',
        'is_active',
        'status',
        'last_seen_at',
        'ip_address',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pairing_token_expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Device $device) {
            if (empty($device->device_code)) {
                $latest = static::withTrashed()->withoutGlobalScopes()->latest('id')->first();
                $nextId = $latest ? $latest->id + 1 : 1;
                $device->device_code = 'DEV-'.str_pad((string) $nextId, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'created_by_device_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function cashDrawerMovements(): HasMany
    {
        return $this->hasMany(CashDrawerMovement::class);
    }

    public function handovers(): HasMany
    {
        return $this->hasMany(ShiftHandover::class);
    }

    public function isPaired(): bool
    {
        return $this->status === self::STATUS_PAIRED && ! empty($this->device_fingerprint);
    }

    /**
     * Resolusi implicit route model binding untuk Device.
     * Mengabaikan StoreScope agar resource dapat ditemukan untuk evaluasi
     * hak akses lintas-cabang oleh DevicePolicy, namun tetap mempertahankan TenantScope.
     *
     * @param  Builder  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return Builder
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query->withoutGlobalScope(StoreScope::class)
            ->where($field ?? $this->getRouteKeyName(), $value);
    }
}
