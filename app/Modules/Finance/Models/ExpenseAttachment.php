<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $expense_id
 * @property string $file_path
 * @property string $file_name
 * @property int $file_size
 * @property string|null $mime_type
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class ExpenseAttachment extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'expense_attachments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }
}
