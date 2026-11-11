<?php

declare(strict_types=1);

namespace App\Modules\Integration\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\BelongsToCompany;
use App\Models\Scopes\TenantScope;
use App\Models\Store;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Langganan Webhook Keluar (Outbound Webhook Subscription).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $company_id
 * @property int|null $store_id
 * @property string $name
 * @property string $target_url
 * @property string $secret_key
 * @property array<int, string> $subscribed_events
 * @property bool $is_active
 * @property int $failure_count
 * @property CarbonInterface|null $last_delivery_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Store|null $store
 */
#[ScopedBy(TenantScope::class)]
class WebhookSubscription extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use BelongsToCompany;
    use HasFactory;

    protected $table = 'webhook_subscriptions';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'store_id',
        'name',
        'target_url',
        'secret_key',
        'subscribed_events',
        'is_active',
        'failure_count',
        'last_delivery_at',
    ];

    protected function casts(): array
    {
        return [
            'subscribed_events' => 'array',
            'is_active' => 'boolean',
            'failure_count' => 'integer',
            'last_delivery_at' => 'datetime',
        ];
    }

    /**
     * Memeriksa apakah langganan webhook ini mendengarkan event tertentu.
     */
    public function isSubscribedTo(string $event): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $events = $this->subscribed_events ?? [];

        return in_array('*', $events, true) || in_array($event, $events, true);
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
     * @return HasMany<WebhookDeliveryLog, $this>
     */
    public function deliveryLogs(): HasMany
    {
        return $this->hasMany(WebhookDeliveryLog::class, 'subscription_id');
    }
}
