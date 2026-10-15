<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * [D2-BE-005] Pivot konsumsi modifier pada baris order: order_item_modifiers.
 * Kolom tenant_id + store_id WAJIB terisi (FK komposit Phase 11 / 2026_12_04_000011).
 */
class OrderItemModifier extends Pivot
{
    use AssignsStore;
    use AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    protected $table = 'order_item_modifiers';

    protected $guarded = [];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }
}
