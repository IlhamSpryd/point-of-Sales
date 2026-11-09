<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Enums\JournalTypeEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $store_id
 * @property string $journal_number
 * @property int $accounting_period_id
 * @property CarbonInterface $posting_date
 * @property JournalTypeEnum $journal_type
 * @property JournalStatusEnum $status
 * @property string $source_module
 * @property string $source_type
 * @property int|null $source_id
 * @property string|null $source_number
 * @property string $idempotency_key
 * @property int|null $posting_rule_version_id
 * @property int|null $reversal_of_journal_id
 * @property int|null $reversed_by_journal_id
 * @property string $currency_code
 * @property numeric-string $total_debit
 * @property numeric-string $total_credit
 * @property string|null $notes
 * @property string|null $reversal_reason
 * @property int|null $created_by_user_id
 * @property int|null $approved_by_user_id
 * @property int|null $posted_by_user_id
 * @property CarbonInterface|null $posted_at
 * @property CarbonInterface|null $reversed_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class JournalEntry extends Model
{
    use AssignsTenant;
    use \App\Core\Traits\BelongsToCompany;
    use ScopedToTenant;

    protected $table = 'journal_entries';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'store_id',
        'journal_number',
        'accounting_period_id',
        'posting_date',
        'journal_type',
        'status',
        'source_module',
        'source_type',
        'source_id',
        'source_number',
        'idempotency_key',
        'posting_rule_version_id',
        'reversal_of_journal_id',
        'reversed_by_journal_id',
        'currency_code',
        'total_debit',
        'total_credit',
        'notes',
        'reversal_reason',
        'created_by_user_id',
        'approved_by_user_id',
        'posted_by_user_id',
        'posted_at',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'posting_date' => 'date',
            'journal_type' => JournalTypeEnum::class,
            'status' => JournalStatusEnum::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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
     * @return BelongsTo<AccountingPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    /**
     * @return BelongsTo<PostingRuleVersion, $this>
     */
    public function postingRuleVersion(): BelongsTo
    {
        return $this->belongsTo(PostingRuleVersion::class, 'posting_rule_version_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reversalOfJournal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_journal_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reversedByJournal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_journal_id');
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id')->orderBy('line_number');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function isPosted(): bool
    {
        return $this->status === JournalStatusEnum::POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === JournalStatusEnum::REVERSED;
    }

    public function isBalanced(): bool
    {
        return bccomp((string) $this->total_debit, (string) $this->total_credit, 2) === 0
            && bccomp((string) $this->total_debit, '0.00', 2) > 0;
    }
}
