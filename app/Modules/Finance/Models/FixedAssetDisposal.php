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
 * @property string $proceeds_amount
 * @property string $book_value_at_disposal
 * @property string $gain_loss_amount
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

    protected $guarded = ['id'];

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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
