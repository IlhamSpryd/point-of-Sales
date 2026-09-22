<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportTaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportTask extends Model
{
    protected $fillable = [
        'requested_by', 'type', 'parameters', 'status',
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
