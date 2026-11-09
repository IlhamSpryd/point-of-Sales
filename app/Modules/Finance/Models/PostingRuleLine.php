<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $posting_rule_version_id
 * @property int $line_sequence
 * @property string $side
 * @property string $amount_source_key
 * @property string $account_resolution_strategy
 * @property int|null $account_id
 * @property string|null $system_account_key
 * @property string $zero_amount_behavior
 * @property string|null $description_template
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class PostingRuleLine extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'posting_rule_lines';

    protected $fillable = [
        'tenant_id',
        'posting_rule_version_id',
        'line_sequence',
        'side',
        'amount_source_key',
        'account_resolution_strategy',
        'account_id',
        'system_account_key',
        'zero_amount_behavior',
        'description_template',
    ];

    protected function casts(): array
    {
        return [
            'line_sequence' => 'integer',
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
     * @return BelongsTo<PostingRuleVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(PostingRuleVersion::class, 'posting_rule_version_id');
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function isDebit(): bool
    {
        return $this->side === 'DEBIT';
    }

    public function isCredit(): bool
    {
        return $this->side === 'CREDIT';
    }
}
