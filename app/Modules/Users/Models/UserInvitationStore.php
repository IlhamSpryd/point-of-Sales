<?php

namespace App\Modules\Users\Models;

use App\Models\Store;
use App\Models\Tenant;
use App\Models\UserInvitation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_invitation_id
 * @property int $store_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserInvitationStore extends Model
{
    use HasFactory;

    protected $table = 'user_invitation_stores';

    protected $fillable = [
        'tenant_id',
        'user_invitation_id',
        'store_id',
    ];

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(UserInvitation::class, 'user_invitation_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
