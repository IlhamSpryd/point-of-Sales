<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $event_code
 * @property string $rule_name
 * @property string $source_domain
 * @property string|null $description
 * @property bool $is_active
 * @property string $approval_status
 * @property int|null $approved_by_user_id
 * @property CarbonInterface|null $approved_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class PostingRule extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'posting_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PostingRuleVersion::class, 'posting_rule_id');
    }

    public function activeVersion(): HasOne
    {
        return $this->hasOne(PostingRuleVersion::class, 'posting_rule_id')
            ->where('status', 'ACTIVE')
            ->latestOfMany('version_number');
    }

    public function isApproved(): bool
    {
        return $this->approval_status === 'APPROVED' && $this->is_active;
    }
}
