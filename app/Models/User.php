<?php

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Services\Context\TenantContext;
use App\Services\EmployeeIdGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use AssignsTenant;
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'name',
        'email',
        'phone_number',
        'password_hash',
        'pin_hash',
        'join_date',
        'role_id',
        'is_active',
        'email_verified_at',
        'failed_login_attempts',
        'locked_until',
        'last_login_at',
    ];

    protected $hidden = [
        'password_hash',
        'pin_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_hash' => 'hashed',
            'pin_hash' => 'hashed', // Amankan PIN seperti password
            'join_date' => 'date',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
        ];
    }

    public function isLockedOut(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function recordFailedLogin(int $maxAttempts = 5, int $lockoutMinutes = 15): bool
    {
        $attempts = ($this->failed_login_attempts ?? 0) + 1;
        $lockedUntil = null;
        $isNowLocked = false;

        if ($attempts >= $maxAttempts) {
            $lockedUntil = now()->addMinutes($lockoutMinutes);
            $isNowLocked = true;
        }

        $this->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $lockedUntil,
        ])->save();

        return $isNowLocked;
    }

    public function resetLoginLockout(): void
    {
        if ($this->failed_login_attempts > 0 || $this->locked_until !== null) {
            $this->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();
        }
    }

    public function getAuthPassword()
    {
        return $this->password_hash ?? $this->password;
    }

    // Otomatis buat Employee ID saat user baru ditambahkan.
    //
    // DB-001 / XD-03: logika generate TIDAK lagi di sini — dipindahkan ke
    // App\Services\EmployeeIdGenerator agar provisioning dan pembuatan user
    // biasa memakai SATU strategi yang sama. `employee_id` di-unique-kan
    // GLOBAL (users_employee_id_unique), jadi nilai wajib unik lintas tenant;
    // maks(id)+1 dan loop exists() tidak dapat menjamin itu pada request
    // konkuren.
    //
    // Presedens: pemanggil yang sudah mengisi employee_id (mis. provisioning)
    // tidak ditimpa. Bila tenant_id belum terisi, AssignsTenant mengisinya
    // lebih dulu pada event `creating` yang sama, sehingga urutan listener
    // tetap benar.
    protected static function booted()
    {
        static::creating(function ($model) {
            if (! empty($model->employee_id)) {
                return;
            }

            if (empty($model->tenant_id)) {
                throw new \RuntimeException(
                    'Tidak dapat membuat employee_id tanpa tenant_id pada user baru.'
                );
            }

            $model->employee_id = EmployeeIdGenerator::next((int) $model->tenant_id);
        });
    }

    // Relasi ke Role
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function stores()
    {
        return $this->belongsToMany(Store::class, 'user_stores');
    }

    /**
     * Route binding di-scope ke tenant pada konteks aktif (404 bila lintas tenant).
     * Login tidak melewati route binding, sehingga tidak terpengaruh (BD-7 aman).
     * Fail-closed: jika tenant context tidak ada dan tidak dalam explicit bypass, tolak resolusi.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return static::query()->where($field ?? $this->getRouteKeyName(), $value)->first();
        }

        if ($context->hasTenant()) {
            return static::query()
                ->where('tenant_id', $context->requireTenantId())
                ->where($field ?? $this->getRouteKeyName(), $value)
                ->first();
        }

        return null;
    }
}
