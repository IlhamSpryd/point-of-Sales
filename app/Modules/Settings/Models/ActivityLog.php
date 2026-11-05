<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $store_id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $description
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ActivityLog extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $fillable = [
        'tenant_id', 'store_id', 'user_id', 'action', 'subject_type', 'subject_id',
        'description', 'old_values', 'new_values', 'ip_address',
        'user_agent', 'metadata',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Activity logs are append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Activity logs are append-only and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
        ];
    }

    /** Relasi (BelongsTo): Pengguna yang melakukan aksi tercatat ini.
     * @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
