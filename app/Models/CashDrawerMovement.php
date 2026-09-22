<?php
declare(strict_types=1);

namespace App\Models;

use App\Enums\CashDrawerMovementCategoryEnum;
use App\Enums\CashDrawerMovementTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashDrawerMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'type' => CashDrawerMovementTypeEnum::class,
        'category' => CashDrawerMovementCategoryEnum::class,
        'amount' => 'decimal:4',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw new \LogicException("Immutable Ledger: Modifikasi data dilarang oleh arsitektur Tier-1.");
    }

    public function delete()
    {
        throw new \LogicException("Immutable Ledger: Modifikasi data dilarang oleh arsitektur Tier-1.");
    }
}
