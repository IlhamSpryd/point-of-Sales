<div class="flex flex-wrap items-center justify-center gap-3">
    <x-components.button variant="outline" x-on:click="window.toast('Saved successfully!', 'success')">Success</x-components.button>
    <x-components.button variant="outline" x-on:click="window.toast('Something went wrong', 'error', 'Please try again later.')">Error</x-components.button>
    <x-components.button variant="outline" x-on:click="window.toast('Heads up', 'warning')">Warning</x-components.button>
    <x-components.button variant="outline" x-on:click="window.toast('New update available', 'info')">Info</x-components.button>
</div>
<x-components.toast />
