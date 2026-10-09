<?php

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelOrderLog extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $fillable = [
        'provider', 'external_order_id', 'status', 'payload', 'error_message', 'order_id',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
