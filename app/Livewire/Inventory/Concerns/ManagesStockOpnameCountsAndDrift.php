<?php

declare(strict_types=1);

namespace App\Livewire\Inventory\Concerns;

use App\Modules\Inventory\Exceptions\StockOpnameDriftException;
use App\Modules\Inventory\Services\StockOpnameService;
use DomainException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

trait ManagesStockOpnameCountsAndDrift
{
    public bool $showCountModal = false;

    public bool $showDriftModal = false;

    // Counting state
    #[Locked]
    public ?int $activeOpnameId = null;

    /**
     * @var array<int, float|string>
     */
    public array $countedQuantities = [];

    /**
     * @var array<int, string>
     */
    public array $countNotes = [];

    // Drift modal data
    /**
     * @var array<int, array{
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
    public array $driftedItems = [];

    public function openCountModal(int $opnameId): void
    {
        $opname = $this->findScopedOpname($opnameId);
        $this->authorize('recordCount', $opname);

        $this->activeOpnameId = $opname->id;
        $this->countedQuantities = [];
        $this->countNotes = [];

        foreach ($opname->items as $item) {
            $this->countedQuantities[$item->id] = $item->counted_quantity !== null ? (string) $item->counted_quantity : '';
            $this->countNotes[$item->id] = $item->notes ?? '';
        }

        $this->showCountModal = true;
    }

    public function closeCountModal(): void
    {
        $this->showCountModal = false;
        $this->activeOpnameId = null;
        $this->countedQuantities = [];
        $this->countNotes = [];
    }

    public function saveCounts(StockOpnameService $service): void
    {
        if (! $this->activeOpnameId) {
            return;
        }

        $opname = $this->findScopedOpname($this->activeOpnameId);
        $this->authorize('recordCount', $opname);
        $user = auth()->user();

        $counts = [];
        foreach ($this->countedQuantities as $itemId => $qty) {
            if ($qty !== '') {
                $counts[(int) $itemId] = [
                    'item_id' => (int) $itemId,
                    'counted_quantity' => (string) $qty,
                    'notes' => $this->countNotes[$itemId] ?? null,
                ];
            }
        }

        try {
            $service->recordCounts($opname, $counts, (int) $user->id);
            session()->flash('message', 'Hasil hitung fisik berhasil disimpan.');
            $this->closeCountModal();
        } catch (InvalidArgumentException|DomainException $e) {
            $this->addError('general', $e->getMessage());
        }
    }

    public function approveOpname(int $opnameId, StockOpnameService $service): void
    {
        $opname = $this->findScopedOpname($opnameId);
        $this->authorize('approve', $opname);
        $user = auth()->user();

        try {
            $service->approveOpname($opname, (int) $user->id);
            session()->flash('message', "Sesi opname #{$opname->opname_number} berhasil disetujui & rekonsiliasi stok telah dicatat ke ledger.");
            $this->showDriftModal = false;
        } catch (StockOpnameDriftException $e) {
            $this->activeOpnameId = $opname->id;
            $this->driftedItems = $e->getDriftedItems();
            $this->showDriftModal = true;
            session()->flash('error', $e->getMessage());
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function resolveDriftItem(int $itemId, string $mode, StockOpnameService $service): void
    {
        if (! $this->activeOpnameId) {
            return;
        }

        $opname = $this->findScopedOpname($this->activeOpnameId);
        $this->authorize('approve', $opname);
        $user = auth()->user();

        try {
            $service->resolveDrift($opname, $itemId, $mode, (int) $user->id);

            // Filter out resolved item from local list
            $this->driftedItems = array_values(array_filter(
                $this->driftedItems,
                fn ($d) => (int) $d['item_id'] !== $itemId
            ));

            if (empty($this->driftedItems)) {
                $this->showDriftModal = false;
                session()->flash('message', 'Seluruh drift telah diresolusi.');
            }
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }
}
