<?php

namespace App\Modules\Customers\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Model Table merepresentasikan satu meja fisik di coffeeshop.
 * Setiap meja punya QR Code unik (dibuat dari secure_token) yang ditempel
 * di atas meja untuk memulai sesi pemesanan self-order.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $store_id
 * @property string $table_code
 * @property string $table_name
 * @property int $capacity
 * @property string|null $area
 * @property bool $is_active
 * @property string $operational_status
 * @property string $secure_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Table extends Model
{
    use AssignsStore, AssignsTenant;
    use HasFactory, SoftDeletes;
    use ScopedToStore;
    use ScopedToTenant;

    protected $table = 'tables'; // eksplisit, agar tidak ambigu dengan nama class 'Table'

    protected $fillable = [
        'tenant_id',
        'store_id',
        'table_code',
        'table_name',
        'capacity',
        'area',
        'is_active',
        'operational_status',
        'secure_token',
        'qr_token',
        'qr_token_version',
        'qr_rotated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'qr_token_version' => 'integer',
            'qr_rotated_at' => 'datetime',
        ];
    }

    /**
     * Setiap kali record Table baru dibuat, secure_token otomatis di-generate
     * sebagai UUID acak — Admin TIDAK PERNAH mengisi kolom ini secara manual,
     * mencegah Admin sengaja/tidak sengaja memilih token yang mudah ditebak.
     */
    protected static function booted(): void
    {
        static::creating(function (Table $table) {
            // Auto-generate Business ID
            if (empty($table->table_code)) {
                $latest = static::withTrashed()->withoutGlobalScopes()->latest('id')->first();
                $nextId = $latest ? $latest->id + 1 : 1;
                $table->table_code = 'YVL-TBL-'.str_pad((string) $nextId, 3, '0', STR_PAD_LEFT);
            }

            // KUNCI KEAMANAN & MULTI-TENANCY: Auto-generate Secure Token sebagai UUID acak berentropi tinggi.
            if (empty($table->secure_token)) {
                $table->secure_token = (string) Str::uuid();
            }

            // Kredensial QR 64-heksadesimal berentropi tinggi (ADR-005)
            if (empty($table->qr_token)) {
                $table->qr_token = bin2hex(random_bytes(32));
            }
            if (empty($table->qr_token_version)) {
                $table->qr_token_version = 1;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Relasi (HasMany): Satu meja bisa memiliki banyak riwayat pesanan
     * dari waktu ke waktu (setiap pelanggan yang duduk & scan QR di sana).
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Relasi (HasMany): Pesanan aktif yang saat ini menempati meja ini di cabang tersebut.
     */
    public function activeOrders(): HasMany
    {
        $query = $this->orders()->whereIn('order_status', ['pending', 'paid']);
        if ($this->store_id) {
            $query->where('orders.store_id', $this->store_id);
        }

        return $query;
    }

    public function isAvailable(): bool
    {
        return $this->operational_status === 'available';
    }

    public function isOccupied(): bool
    {
        return $this->operational_status === 'occupied';
    }

    public function getStatusAttribute(): string
    {
        return $this->operational_status;
    }

    public function setStatusAttribute(string $value): void
    {
        $this->operational_status = $value;
    }

    /**
     * KUNCI INTEGRASI QR: route /order/{table} akan resolve pakai
     * secure_token atau qr_token, TIDAK PERNAH pakai id mentah di URL publik.
     */
    public function getRouteKeyName(): string
    {
        return 'qr_token';
    }
}
