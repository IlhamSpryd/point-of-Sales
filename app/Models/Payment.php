<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use AssignsStore, AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'integer',
        'amount_tendered' => 'integer',
        'change_amount' => 'integer',
        'payment_method' => PaymentMethod::class,
        'status' => PaymentStatusEnum::class,
    ];

    public function paymentAttempt(): HasOne
    {
        return $this->hasOne(PaymentAttempt::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function getRefundedAmount(): int
    {
        return (int) $this->refunds()
            ->whereNotIn('request_status', [PaymentRefund::REQUEST_FAILED, PaymentRefund::REQUEST_CANCELLED])
            ->sum('amount');
    }

    public function getRefundableBalance(): int
    {
        return max(0, (int) $this->amount - $this->getRefundedAmount());
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw new \LogicException('Immutable Ledger: Modifikasi data dilarang oleh arsitektur Tier-1.');
    }

    public function delete()
    {
        throw new \LogicException('Immutable Ledger: Modifikasi data dilarang oleh arsitektur Tier-1.');
    }
}
