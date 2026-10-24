<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use DomainException;

/**
 * Exception yang dilempar saat Item-Level Drift Gate mendeteksi mutasi sela
 * antara waktu snapshot fisik dan waktu approval (OD-04).
 */
class StockOpnameDriftException extends DomainException
{
    /**
     * @param  array<int, array{
     *     item_id: int,
     *     item_type: string,
     *     product_id?: ?int,
     *     ingredient_id?: ?int,
     *     name: string,
     *     snapshot_on_hand: string,
     *     current_on_hand: string,
     *     snapshot_last_movement_id: ?int,
     *     current_last_movement_id: ?int,
     *     drift_delta: string
     * }>  $driftedItems
     */
    public function __construct(
        protected array $driftedItems,
        string $message = 'DRIFT_DETECTED: Saldo stok telah berubah sejak snapshot diambil akibat mutasi sela (penjualan POS / mutasi lain). Persetujuan otomatis diblokir sesuai OD-04.'
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<int, array{
     *     item_id: int,
     *     item_type: string,
     *     product_id?: ?int,
     *     ingredient_id?: ?int,
     *     name: string,
     *     snapshot_on_hand: string,
     *     current_on_hand: string,
     *     snapshot_last_movement_id: ?int,
     *     current_last_movement_id: ?int,
     *     drift_delta: string
     * }>
     */
    public function getDriftedItems(): array
    {
        return $this->driftedItems;
    }
}
