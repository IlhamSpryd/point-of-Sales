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
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-2 sm:translate-y-0 sm:translate-x-2"
    x-transition:enter-end="opacity-100 translate-y-0 sm:translate-x-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed bottom-4 right-4 z-[9999] flex items-center p-4 rounded-xl shadow-lg border"
    :class="type === 'success' ? 'bg-success-50 border-success-200 text-success-800' : (type === 'danger' ? 'bg-danger-50 border-danger-200 text-danger-800' : (type === 'warning' ? 'bg-warning-50 border-warning-200 text-warning-800' : 'bg-info-50 border-info-200 text-info-800'))">
    
    <span class="material-symbols-rounded mr-2" x-text="type === 'success' ? 'check_circle' : (type === 'danger' ? 'error' : (type === 'warning' ? 'warning' : 'info'))"></span>
    <span class="text-sm font-medium" x-text="message"></span>
    <button type="button" @click="show = false" class="ml-4 opacity-50 hover:opacity-100 transition-opacity flex items-center justify-center">
        <span class="material-symbols-rounded text-[18px]">close</span>
    </button>
</div>
