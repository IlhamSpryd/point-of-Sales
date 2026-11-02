<?php

namespace App\Livewire\Concerns;

/**
 * UI State Concern for Data Table Sorting.
 *
 * NOTE (RK-07): This trait MUST ONLY manage UI sorting state (field and direction).
 * It MUST NEVER execute unbounded queries or mutate tenant scoping.
 */
trait WithSorting
{
    public string $sortField = 'created_at';
    public string $sortDirection = 'desc';

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }
}
