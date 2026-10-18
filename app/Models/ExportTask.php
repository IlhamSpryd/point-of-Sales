<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportTaskStatus;
use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $store_id
 * @property int $requested_by
 * @property string $type
 * @property array<string, mixed>|null $parameters
 * @property ExportTaskStatus $status
 * @property string|null $file_path
 * @property int|null $file_size_bytes
 * @property string|null $error_message
 * @property CarbonInterface|null $completed_at
 */
class ExportTask extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    protected $fillable = [
        'tenant_id', 'store_id', 'requested_by', 'type', 'parameters', 'status',
        'file_path', 'file_size_bytes', 'error_message', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'status' => ExportTaskStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }
}
