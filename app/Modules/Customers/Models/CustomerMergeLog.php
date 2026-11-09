<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $source_customer_id
 * @property int $target_customer_id
 * @property int|null $merged_by_user_id
 */
class CustomerMergeLog extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'source_customer_id',
        'target_customer_id',
        'merged_by_user_id',
        'reason',
        'reassigned_records_json',
    ];

    protected function casts(): array
    {
        return [
            'reassigned_records_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function sourceCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'source_customer_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function targetCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'target_customer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function mergedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by_user_id');
    }
}
