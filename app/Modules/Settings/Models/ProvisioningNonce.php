<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Domain 1 — baris anti-replay nonce. Lihat migration untuk rationale.
 */
class ProvisioningNonce extends Model
{
    protected $primaryKey = 'nonce';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = ['nonce', 'expires_at_epoch'];

    protected $casts = [
        'expires_at_epoch' => 'integer',
    ];
}
