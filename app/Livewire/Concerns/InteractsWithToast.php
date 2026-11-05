<?php

namespace App\Livewire\Concerns;

/**
 * Standardized Toast Dispatch Concern for Livewire Components (IX-04).
 * Dispatches uniform 'toast' browser window events captured by <x-ui.toast />.
 */
trait InteractsWithToast
{
    /**
     * Dispatch a standard toast notification.
     *
     * @param  string  $type  'success'|'error'|'warning'|'info'
     * @param  array{href: string, label: string}|null  $action
     */
    protected function toast(string $message, string $type = 'success', string $description = '', ?array $action = null): void
    {
        $this->dispatch('toast', [
            'message' => $message,
            'type' => $type,
            'description' => $description,
            'action' => $action,
        ]);
    }

    /**
     * Dispatch a success toast.
     */
    protected function toastSuccess(string $message, string $description = ''): void
    {
        $this->toast($message, 'success', $description);
    }

    /**
     * Dispatch an error toast.
     */
    protected function toastError(string $message, string $description = ''): void
    {
        $this->toast($message, 'error', $description);
    }

    /**
     * Dispatch a warning toast.
     */
    protected function toastWarning(string $message, string $description = ''): void
    {
        $this->toast($message, 'warning', $description);
    }

    /**
     * Dispatch an informative toast.
     */
    protected function toastInfo(string $message, string $description = ''): void
    {
        $this->toast($message, 'info', $description);
    }
}
