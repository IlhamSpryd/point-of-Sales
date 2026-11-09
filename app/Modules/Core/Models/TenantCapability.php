<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy(TenantScope::class)]
class TenantCapability extends Model
{
    use HasFactory;

    protected $table = 'tenant_capabilities';

    protected $fillable = [
        'tenant_id',
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
     * @return BelongsTo<CapabilityDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(CapabilityDefinition::class, 'capability_code', 'code');
    }
}
