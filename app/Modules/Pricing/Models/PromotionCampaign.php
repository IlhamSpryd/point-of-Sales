<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStoreForDiscount;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromotionCampaign extends Model
{
    use AssignsTenant;
    use HasFactory, SoftDeletes;
    use ScopedToStoreForDiscount;
    use ScopedToTenant;

    public const TYPE_AUTOMATIC_DISCOUNT = 'AUTOMATIC_DISCOUNT';
    public const TYPE_BUY_X_GET_Y = 'BUY_X_GET_Y';
    public const TYPE_BUNDLE_PACKAGE = 'BUNDLE_PACKAGE';
    public const TYPE_HAPPY_HOUR = 'HAPPY_HOUR';

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_SCHEDULED = 'SCHEDULED';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_PAUSED = 'PAUSED';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_ARCHIVED = 'ARCHIVED';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'campaign_code',
        'name',
        'description',
        'type',
        'start_datetime',
        'end_datetime',
        'allowed_days',
        'start_time',
        'end_time',
        'allowed_channels',
        'allowed_customer_group_ids',
        'priority',
        'max_usage_limit',
        'max_usage_per_customer',
        'current_usage_count',
        'is_stackable',
        'status',
        'created_by_user_id',
        'approved_by_user_id',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'allowed_days' => 'array',
            'allowed_channels' => 'array',
            'allowed_customer_group_ids' => 'array',
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
            'approved_at' => 'datetime',
            'is_stackable' => 'boolean',
            'priority' => 'integer',
            'max_usage_limit' => 'integer',
            'max_usage_per_customer' => 'integer',
            'current_usage_count' => 'integer',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PromotionRule::class, 'campaign_id');
    }

    public function rewards(): HasManyThrough
    {
        return $this->hasManyThrough(PromotionReward::class, PromotionRule::class, 'campaign_id', 'rule_id');
    }

    public function storeMappings(): HasMany
    {
        return $this->hasMany(PromotionStoreMapping::class, 'campaign_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(PromotionReservation::class, 'campaign_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
