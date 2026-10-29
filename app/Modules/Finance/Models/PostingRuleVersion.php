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

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $posting_rule_id
 * @property int $version_number
 * @property CarbonInterface $effective_from
 * @property CarbonInterface|null $effective_to
 * @property string $status
 * @property string|null $change_summary
 * @property int|null $created_by_user_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class PostingRuleVersion extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'posting_rule_versions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PostingRule::class, 'posting_rule_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PostingRuleLine::class, 'posting_rule_version_id')->orderBy('line_sequence');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
