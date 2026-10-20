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
        'table_code',
        'table_name',
        'capacity',
        'area',
        'is_active',
        'operational_status',
        'secure_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
            // Mencegah kolisi antar meja/tenant dengan nama sama dan mencegah penebakan token URL (OR-008).
            if (empty($table->secure_token)) {
                $table->secure_token = (string) Str::uuid();
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
     * KUNCI INTEGRASI QR: route /order/{table} akan resolve pakai
     * secure_token, TIDAK PERNAH pakai id mentah di URL publik.
     */
    public function getRouteKeyName(): string
    {
        return 'secure_token';
    }
}
