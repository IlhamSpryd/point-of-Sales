<?php

namespace App\Livewire\Concerns;

/**
 * UI State Concern for Data Table Bulk Actions.
 *
 * NOTE (RK-07): This trait MUST ONLY manage UI selection state.
 * Actual bulk mutations must be executed within domain services and scoped to tenant_id.
 */
trait WithBulkActions
{
    /** @var array<int, string> */
    public array $selected = [];

    public bool $selectAll = false;

    public function updatedSelectAll(bool $value): void
    {
        if (! $value) {
            $this->selected = [];
        } else {
            if (method_exists($this, 'getSelectableIds')) {
                /** @var array<int|string> $ids */
                $ids = $this->getSelectableIds();
                $this->selected = array_values(array_map('strval', $ids));
            }
        }
    }

    public function updatedSelected(): void
    {
        $this->selectAll = false;
    }

    /**
     * @param array<int|string> $pageIds
     */
    public function selectPage(array $pageIds): void
    {
        $stringIds = array_map('strval', $pageIds);
        $this->selected = array_values(array_unique(array_merge($this->selected, $stringIds)));
    }

    public function clearSelected(): void
    {
        $this->selected = [];
        $this->selectAll = false;
    }

    public function isSelected(int|string $id): bool
    {
        return in_array((string) $id, $this->selected, true);
    }

    public function getSelectedCountProperty(): int
    {
        return count($this->selected);
    }
}
