<?php

declare(strict_types=1);

namespace App\Modules\Integration\Models;

use App\Core\Traits\AssignsTenant;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Log Pengiriman Webhook Keluar.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $subscription_id
 * @property string $delivery_id
 * @property string $event
 * @property string $payload
 * @property int|null $response_status
 * @property string|null $response_body
 * @property int $attempt
 * @property string $status
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read WebhookSubscription $subscription
 * @property-read Tenant $tenant
 */
#[ScopedBy(TenantScope::class)]
class WebhookDeliveryLog extends Model
{
    use AssignsTenant;
    use HasFactory;

    protected $table = 'webhook_delivery_logs';

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'delivery_id',
        'event',
        'payload',
        'response_status',
        'response_body',
        'attempt',
        'status',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'attempt' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
