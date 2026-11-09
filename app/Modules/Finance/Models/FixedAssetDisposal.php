<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\FixedAssetDisposalTypeEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $fixed_asset_id
 * @property CarbonInterface|string $disposal_date
 * @property FixedAssetDisposalTypeEnum $disposal_type
 * @property numeric-string $proceeds_amount
 * @property numeric-string $book_value_at_disposal
 * @property numeric-string $gain_loss_amount
 * @property int|null $journal_entry_id
 * @property int|null $cash_bank_account_id
 * @property string|null $reason
 * @property int|null $approved_by_user_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class FixedAssetDisposal extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'fixed_asset_disposals';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'fixed_asset_id',
        'disposal_date',
        'disposal_type',
        'proceeds_amount',
        'book_value_at_disposal',
        'gain_loss_amount',
        'journal_entry_id',
        'cash_bank_account_id',
        'reason',
        'approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'disposal_date' => 'date',
            'disposal_type' => FixedAssetDisposalTypeEnum::class,
            'proceeds_amount' => 'string',
            'book_value_at_disposal' => 'string',
            'gain_loss_amount' => 'string',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<FixedAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
