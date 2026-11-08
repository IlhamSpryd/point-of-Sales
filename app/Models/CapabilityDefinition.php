<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $industry
 * @property string|null $description
 * @property list<string>|null $dependencies
 * @property list<string>|null $incompatible_with
 * @property bool $is_active
 */
class CapabilityDefinition extends Model
{
    use HasFactory;

    protected $table = 'capability_definitions';

    protected $fillable = [
        'code',
        'name',
        'industry',
        'description',
        'dependencies',
        'incompatible_with',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'dependencies' => 'array',
            'incompatible_with' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<TenantCapability, $this>
     */
    public function tenantCapabilities(): HasMany
    {
        return $this->hasMany(TenantCapability::class, 'capability_code', 'code');
    }
}
