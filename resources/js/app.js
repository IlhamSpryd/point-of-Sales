import Alpine from 'alpinejs';

document.addEventListener('DOMContentLoaded', () => {
    if (typeof window.Livewire === 'undefined' && !window.Alpine) {
        window.Alpine = Alpine;
        Alpine.start();
    }
});
