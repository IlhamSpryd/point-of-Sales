<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelOrderLog extends Model
{
    protected $fillable = [
        'provider', 'external_order_id', 'status', 'payload', 'error_message'
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
