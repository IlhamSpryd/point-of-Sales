<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesCreditNoteApplication extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'sales_credit_note_applications';

    protected $fillable = [
        'tenant_id',
        'sales_credit_note_id',
        'customer_invoice_id',
        'applied_amount',
        'applied_by_user_id',
        'applied_at',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'applied_amount' => 'decimal:2',
            'applied_at' => 'datetime',
        ];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(SalesCreditNote::class, 'sales_credit_note_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id')->withTrashed();
    }
}
