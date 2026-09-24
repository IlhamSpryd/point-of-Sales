<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use HasFactory, SoftDeletes;

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
                $latest = static::latest('id')->first();
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
        return is_array($this->permissions) && in_array($permission, $this->permissions, true);
    }

    public function getIsAdminAttribute(): bool
    {
        return strtolower($this->name) === 'admin' || strtolower($this->name) === 'superadmin';
    }
}
