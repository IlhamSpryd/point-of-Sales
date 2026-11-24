<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToStore;
use App\Core\Traits\ScopedToTenant;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TableSession merepresentasikan sesi kunjungan tamu di meja tertentu.
 * Memisahkan QR Code fisik statis (pada stiker akrilik meja) dari sesi kunjungan dinamis,
 * sehingga mencegah replay attack dari luar restoran setelah tamu selesai/checkout.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $table_id
 * @property string $session_token
 * @property string|null $check_in_pin
 * @property string $status
 * @property string|null $guest_name
 * @property int $guest_count
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TableSession extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;
    use ScopedToStore;
    use ScopedToTenant;

    protected $table = 'table_sessions';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'table_id',
        'session_token',
        'check_in_pin',
        'status',
        'guest_name',
        'guest_count',
        'opened_at',
        'closed_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'guest_count' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'table_session_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || ($this->expires_at !== null && $this->expires_at->isPast());
    }

    public function close(): void
    {
        $this->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);
    }
}
