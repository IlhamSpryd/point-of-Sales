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
                            this.errorMessage = data.error_message || 'Ekspor gagal diproses.';
                            clearInterval(interval);
                            $wire.resetExport();
                        }
                    })
                    .catch(err => {
                        console.error('Error polling status:', err);
                        this.loading = false;
                        this.status = 'failed';
                        this.errorMessage = 'Gagal memeriksa status ekspor (kesalahan jaringan atau server).';
                        clearInterval(interval);
                        $wire.resetExport();
                    });
            }, 2000);
        }
    }" 
    class="w-full sm:w-auto"
>
    <!-- Idle State / Request Export -->
    <button 
        x-show="!taskId || status === 'failed'" 
        @click="if (status === 'failed') { $wire.resetExport(); status = 'idle'; errorMessage = ''; } $wire.requestExport()"
        class="w-full h-10 bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 rounded-md px-4 py-2 text-sm font-medium transition-colors flex items-center justify-center gap-2"
    >
        <span class="material-symbols-rounded text-[18px]">download</span> Request Export Excel
    </button>
    
    <!-- Processing State -->
    <button 
        x-show="status === 'processing'" 
        disabled
        class="w-full h-10 bg-white text-gray-500 border border-gray-300 rounded-md px-4 py-2 text-sm font-medium flex items-center justify-center gap-2 opacity-75 cursor-not-allowed"
    >
        <svg class="animate-spin h-4 w-4 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        Preparing Export...
    </button>

    <!-- Completed State -->
    <a 
        x-show="status === 'completed'" 
        x-bind:href="downloadUrl"
        class="w-full h-10 bg-apeiron-ink text-white hover:bg-[#111111] rounded-xl px-4 py-2 text-sm font-semibold transition-all duration-200 shadow-sm active:scale-95 flex items-center justify-center gap-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-apeiron-ink focus-visible:ring-offset-2"
        download
    >
        <span class="material-symbols-rounded text-[18px]">check_circle</span> Download File Ready
    </a>

    <!-- Polling Error Message -->
    <div x-show="errorMessage" class="text-xs text-rose-600 mt-1.5 flex items-center gap-1 font-medium" x-cloak>
        <span class="material-symbols-rounded text-[14px]">error</span>
        <span x-text="errorMessage"></span>
    </div>

    <!-- Livewire Validation Errors -->
    @error('range')
        <div class="text-xs text-rose-600 mt-1.5 flex items-center gap-1 font-medium">
            <span class="material-symbols-rounded text-[14px]">error</span>
            <span>{{ $message }}</span>
        </div>
    @enderror
    @error('startDate')
        <div class="text-xs text-rose-600 mt-1.5 flex items-center gap-1 font-medium">
            <span class="material-symbols-rounded text-[14px]">error</span>
            <span>{{ $message }}</span>
        </div>
    @enderror
    @error('endDate')
        <div class="text-xs text-rose-600 mt-1.5 flex items-center gap-1 font-medium">
            <span class="material-symbols-rounded text-[14px]">error</span>
            <span>{{ $message }}</span>
        </div>
    @enderror
</div>
