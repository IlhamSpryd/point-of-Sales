<div x-data="{ 
        show: false, 
        message: '', 
        type: 'success',
        duration: 3000,
        timer: null,
        notify(event) {
            this.message = event.detail.message || '';
            this.type = event.detail.type || 'success';
            this.duration = event.detail.duration || 3000;
            this.show = true;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.show = false, this.duration);
        }
    }" 
    @toast.window="notify"
    x-show="show" 
    x-cloak
    role="status"
    aria-live="polite"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-2 sm:translate-y-0 sm:translate-x-2"
    x-transition:enter-end="opacity-100 translate-y-0 sm:translate-x-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed bottom-4 right-4 z-[9999] flex items-center p-4 rounded-xl shadow-lg border"
    :class="type === 'success' ? 'bg-success-muted border-success-border text-success' : ((type === 'danger' || type === 'error') ? 'bg-destructive-muted border-destructive-border text-destructive' : (type === 'warning' ? 'bg-warning-muted border-warning-border text-warning' : 'bg-info-muted border-info-border text-info'))">
    
    <span class="material-symbols-rounded mr-2" aria-hidden="true" x-text="type === 'success' ? 'check_circle' : ((type === 'danger' || type === 'error') ? 'error' : (type === 'warning' ? 'warning' : 'info'))"></span>
    <span class="text-sm font-medium" x-text="message"></span>
    <button type="button" @click="show = false" aria-label="Tutup notifikasi" class="ml-4 text-muted-foreground hover:text-foreground p-1 rounded-md hover:bg-accent transition-colors flex items-center justify-center">
        <span class="material-symbols-rounded text-[18px]" aria-hidden="true">close</span>
    </button>
</div>
