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

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'line_sequence' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PostingRuleVersion::class, 'posting_rule_version_id');
    }

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
