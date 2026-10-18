<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftHandover extends Model
{
    use AssignsStore, AssignsTenant;
    use HasFactory;
    use ScopedToStore, ScopedToTenant;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'shift_id',
        'device_id',
        'from_user_id',
        'to_user_id',
        'approved_by_user_id',
        'expected_cash_at_handover',
        'counted_cash_at_handover',
        'discrepancy_amount',
        'status',
        'notes',
        'initiated_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_cash_at_handover' => 'integer',
            'counted_cash_at_handover' => 'integer',
            'discrepancy_amount' => 'integer',
            'initiated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
