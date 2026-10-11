<?php

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    // Auto-generate Business ID
    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->role_code)) {
                $latest = static::withoutTenantScope()->latest('id')->first();
                $nextId = $latest ? $latest->id + 1 : 1;
                $model->role_code = 'ROL-'.str_pad($nextId, 3, '0', STR_PAD_LEFT);
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

    public function getIsAdminAttribute(): bool
    {
        return strtolower($this->name) === 'admin' || strtolower($this->name) === 'superadmin';
    }
}
