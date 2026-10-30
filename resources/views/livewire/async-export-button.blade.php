<div x-data="{
        taskId: @entangle('taskId'),
        status: 'idle',
        downloadUrl: null,
        errorMessage: '',
        loading: false,
        init() {
            this.$watch('taskId', value => {
                if(value) {
                    this.pollStatus();
                } else {
                    this.status = 'idle';
                    this.loading = false;
                }
            });
        },
        pollStatus() {
            this.loading = true;
            this.status = 'processing';
            this.errorMessage = '';
            
            let interval = setInterval(() => {
                if(!this.taskId) {
                    clearInterval(interval);
                    return;
                }
                
                fetch(`/exports/${this.taskId}/status`)
                    .then(res => {
                        if (!res.ok) {
                            throw new Error('HTTP ' + res.status);
                        }
                        return res.json();
                    })
                    .then(data => {
                        this.status = data.status;
                        if(data.status === 'completed') {
                            this.downloadUrl = data.download_url;
                            this.loading = false;
                            clearInterval(interval);
                        } else if(data.status === 'failed') {
                            this.loading = false;
                            this.errorMessage = data.error_message || 'Export failed to process.';
                            clearInterval(interval);
                            $wire.resetExport();
                        }
                    })
                    .catch(err => {
                        console.error('Error polling status:', err);
                        this.loading = false;
                        this.status = 'failed';
                        this.errorMessage = 'Failed to check export status (network or server error).';
                        clearInterval(interval);
                        $wire.resetExport();
                    });
            }, 2000);
        }
    }" 
    class="w-full sm:w-auto inline-block"
>
    <!-- Idle State / Request Export -->
    <button 
        x-show="!taskId || status === 'failed'" 
        @click="if (status === 'failed') { $wire.resetExport(); status = 'idle'; errorMessage = ''; } $wire.requestExport()"
        type="button"
        class="inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-semibold rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-200 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition shadow-xs w-full sm:w-auto"
    >
        <span class="material-symbols-rounded text-base">download</span>
        Export to Excel (.xlsx)
    </button>
    
    <!-- Processing State -->
    <button 
        x-show="status === 'processing'" 
        disabled
        type="button"
        class="inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-semibold rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/60 text-zinc-400 dark:text-zinc-500 opacity-75 cursor-not-allowed w-full sm:w-auto"
    >
        <svg class="animate-spin h-4 w-4 text-apeiron-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        Preparing Export...
    </button>

    <!-- Completed State -->
    <a 
        x-show="status === 'completed'" 
        x-bind:href="downloadUrl"
        class="inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-semibold rounded-xl bg-emerald-600 text-white hover:bg-emerald-700 transition shadow-xs w-full sm:w-auto"
        download
    >
        <span class="material-symbols-rounded text-base">check_circle</span>
        Download File
    </a>

    <!-- Polling Error Message -->
    <div x-show="errorMessage" class="text-xs text-rose-600 dark:text-rose-400 mt-1.5 flex items-center gap-1 font-medium" x-cloak>
        <span class="material-symbols-rounded text-[14px]">error</span>
        <span x-text="errorMessage"></span>
    </div>

    <!-- Livewire Validation Errors -->
    @error('range')
        <div class="text-xs text-rose-600 dark:text-rose-400 mt-1.5 flex items-center gap-1 font-medium">
            <span class="material-symbols-rounded text-[14px]">error</span>
            <span>{{ $message }}</span>
        </div>
    @enderror
    @error('startDate')
        <div class="text-xs text-rose-600 dark:text-rose-400 mt-1.5 flex items-center gap-1 font-medium">
            <span class="material-symbols-rounded text-[14px]">error</span>
            <span>{{ $message }}</span>
        </div>
    @enderror
    @error('endDate')
        <div class="text-xs text-rose-600 dark:text-rose-400 mt-1.5 flex items-center gap-1 font-medium">
            <span class="material-symbols-rounded text-[14px]">error</span>
            <span>{{ $message }}</span>
        </div>
    @enderror
</div>
