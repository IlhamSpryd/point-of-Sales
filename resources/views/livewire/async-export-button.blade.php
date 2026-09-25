<div x-data="{
        taskId: @entangle('taskId'),
        status: 'idle',
        downloadUrl: null,
        loading: false,
        init() {
            this.$watch('taskId', value => {
                if(value) {
                    this.pollStatus();
                }
            });
        },
        pollStatus() {
            this.loading = true;
            this.status = 'processing';
            
            let interval = setInterval(() => {
                if(!this.taskId) {
                    clearInterval(interval);
                    return;
                }
                
                fetch(`/exports/${this.taskId}/status`)
                    .then(res => res.json())
                    .then(data => {
                        this.status = data.status;
                        if(data.status === 'completed') {
                            this.downloadUrl = data.download_url;
                            this.loading = false;
                            clearInterval(interval);
                        } else if(data.status === 'failed') {
                            this.loading = false;
                            clearInterval(interval);
                            alert('Export failed: ' + data.error_message);
                        }
                    })
                    .catch(err => {
                        console.error('Error polling status:', err);
                    });
            }, 2000);
        }
    }" 
    class="w-full sm:w-auto"
>
    <!-- Idle State / Request Export -->
    <button 
        x-show="!taskId || status === 'failed'" 
        wire:click="requestExport" 
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
        class="w-full h-10 bg-yovel-ink text-white hover:bg-yovel-ink rounded-md px-4 py-2 text-sm font-medium transition-colors flex items-center justify-center gap-2"
        download
    >
        <span class="material-symbols-rounded text-[18px]">check_circle</span> Download File Ready
    </a>
</div>
