<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $rule_category
 * @property string $jurisdiction
 * @property string $version_code
 * @property CarbonInterface $effective_from
 * @property CarbonInterface|null $effective_to
 * @property string $legal_reference
 * @property array<string, mixed> $rule_parameters
 * @property bool $is_locked
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class StatutoryRuleVersion extends Model
{
    protected $table = 'statutory_rule_versions';

    protected $fillable = [
        'rule_category',
        'jurisdiction',
        'version_code',
        'effective_from',
        'effective_to',
        'legal_reference',
        'rule_parameters',
        'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'rule_parameters' => 'array',
            'is_locked' => 'boolean',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActiveOn(Builder $query, CarbonInterface|string $date): Builder
    {
        $dateStr = is_string($date) ? $date : $date->toDateString();

        return $query->where('effective_from', '<=', $dateStr)
            ->where(function (Builder $q) use ($dateStr) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $dateStr);
            });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCategory(Builder $query, string $category, string $jurisdiction = 'ID'): Builder
    {
        return $query->where('jurisdiction', $jurisdiction)
            ->where('rule_category', $category);
    }
}
