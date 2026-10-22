<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model DocumentSequence — Pengelola generator nomor urut dokumen atomik.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $store_id
 * @property string $scope_key
 * @property string $doc_type
 * @property string $period_key
 * @property string $prefix
 * @property int $last_value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Store|null $store
 */
class DocumentSequence extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'document_sequences';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'scope_key',
        'doc_type',
        'period_key',
        'prefix',
        'last_value',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'store_id' => 'integer',
            'last_value' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
