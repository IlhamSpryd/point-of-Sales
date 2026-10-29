<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CustomerCreditLog
 *
 * Catatan audit append-only untuk setiap perubahan limit, terms, status,
 * reservasi eksposur, atau otorisasi override kredit.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property string $event_type
 * @property array|null $old_values_json
 * @property array|null $new_values_json
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $actor_user_id
 * @property string|null $reason
 * @property Carbon $created_at
 */
class CustomerCreditLog extends Model
{
    use AssignsTenant, ScopedToTenant;

    public const UPDATED_AT = null;

    protected $table = 'customer_credit_logs';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'event_type',
        'old_values_json',
        'new_values_json',
        'reference_type',
        'reference_id',
        'actor_user_id',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values_json' => 'array',
            'new_values_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
