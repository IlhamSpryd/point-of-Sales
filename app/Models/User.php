<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'name',
        'email',
        'phone_number',
        'password',
        'pin_code',
        'join_date',
        'role_id',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'pin_code',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin_code' => 'hashed', // Amankan PIN seperti password
            'join_date' => 'date',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    // Otomatis buat Employee ID saat user baru ditambahkan
    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->employee_id)) {
                $latest = static::latest('id')->first();
                $nextId = $latest ? $latest->id + 1 : 1;
                $model->employee_id = 'YVL-EMP-'.date('Y').'-'.str_pad($nextId, 4, '0', STR_PAD_LEFT);
            }
        });
    }

    // Relasi ke Role
    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
