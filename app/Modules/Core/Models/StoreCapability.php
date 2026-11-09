<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy(TenantScope::class)]
class StoreCapability extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;

    protected $table = 'store_capabilities';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'capability_code',
        'is_enabled',
        'configuration',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'configuration' => 'array',
        ];
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
     * @return BelongsTo<CapabilityDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(CapabilityDefinition::class, 'capability_code', 'code');
    }
}
