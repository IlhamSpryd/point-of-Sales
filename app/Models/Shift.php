<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'opening_balance', 'closing_balance', 'expected_cash',
        'cash_difference', 'status', 'opened_at', 'closed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** Relasi (BelongsTo): Shift ini dibuka oleh satu Kasir spesifik. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** Relasi (HasMany): Satu shift menaungi banyak transaksi selama sesi berjalan. */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
