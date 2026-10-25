<?php

namespace App\Modules\Users\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $role_code
 * @property string $name
 * @property string|null $description
 * @property array<string>|null $permissions
 * @property bool $is_active
 * @property bool $is_admin
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Role extends Model
{
    use AssignsTenant;
    use HasFactory, SoftDeletes;
    use ScopedToTenant;

    protected $fillable = [
        'role_code',
        'name',
        'description',
        'permissions',
        'is_active',
    ];

    // Casting agar data JSON otomatis dibaca sebagai array di Laravel
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // Auto-generate Business ID yang collision-safe (TX-008)
    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->role_code)) {
                $slug = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $model->name), 0, 8));
                if ($slug !== '') {
                    $candidate = 'ROL-'.$slug;
                    $exists = static::withoutTenantScope()
                        ->where('tenant_id', $model->tenant_id)
                        ->where('role_code', $candidate)
                        ->exists();
                    if (! $exists) {
                        $model->role_code = $candidate;

                        return;
                    }
                }

                $model->role_code = 'ROL-'.strtoupper(Str::random(6));
            }
        });
    }

    // Relasi ke tabel Users
    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function hasPermission(string $permission): bool
    {
        if (! is_array($this->permissions)) {
            return false;
        }

        // `*` is the explicit full-access grant; dotted wildcards match only
        // descendants (e.g. orders.* matches orders.view, not orders).
        foreach ($this->permissions as $grant) {
            if ($grant === '*'
                || $grant === $permission
                || (str_ends_with((string) $grant, '.*')
                    && str_starts_with($permission, substr((string) $grant, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    public function setIsAdminAttribute($value): void
    {
        // Virtual attribute; ignored during database insertion
    }

    public function getIsAdminAttribute(): bool
    {
        return strtolower($this->name) === 'admin' || strtolower($this->name) === 'superadmin';
    }

    public function setDisplayNameAttribute($value): void
    {
        if (empty($this->attributes['description']) && is_string($value)) {
            $this->attributes['description'] = $value;
        }
    }

    public function getDisplayNameAttribute(): string
    {
        return (string) ($this->attributes['description'] ?? $this->attributes['name'] ?? '');
    }
}
