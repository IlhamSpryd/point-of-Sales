<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMergeLog extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reassigned_records_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function sourceCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'source_customer_id')->withTrashed();
    }

    public function targetCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'target_customer_id');
    }

    public function mergedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by_user_id');
    }
}
