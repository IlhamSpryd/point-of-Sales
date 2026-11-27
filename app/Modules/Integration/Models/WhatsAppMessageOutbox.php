<?php

declare(strict_types=1);

namespace App\Modules\Integration\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * TR-006: Transactional Outbox untuk WhatsApp Business API.
 * Menjamin isolasi penuh (DEC-007) sehingga kegagalan provider tidak memblokir transaksi ERP.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $recipient_phone
 * @property string $message_type
 * @property string|null $template_id
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property string|null $provider_message_id
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $sent_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WhatsAppMessageOutbox extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $table = 'whatsapp_message_outbox';

    protected $fillable = [
        'tenant_id',
        'recipient_phone',
        'message_type',
        'template_id',
        'payload',
        'status',
        'attempts',
        'last_error',
        'provider_message_id',
        'lease_expires_at',
        'sent_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'payload' => 'array',
        'attempts' => 'integer',
        'lease_expires_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
