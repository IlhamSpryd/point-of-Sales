<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoyaltyLedgerTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyLedger extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'loyalty_ledger';

    protected $guarded = ['id'];

    protected $casts = [
        'type' => LoyaltyLedgerTypeEnum::class,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
