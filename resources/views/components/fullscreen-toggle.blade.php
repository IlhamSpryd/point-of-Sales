<div x-data="{
    isFullscreen: false,
    toggleFullscreen() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(err => {
                console.error('Gagal masuk mode fullscreen:', err);
            });
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            }
        }
    },
    checkFullscreen() {
        this.isFullscreen = !!document.fullscreenElement;
    }
}"
@fullscreenchange.window="checkFullscreen()"
@keydown.window.ctrl.f.prevent="toggleFullscreen()">
    {{-- Tombol sentuh untuk perangkat tanpa keyboard (tablet POS, kios).
         Shortcut Ctrl+F tetap tersedia untuk desktop. --}}
    <button type="button" @click="toggleFullscreen()" :aria-pressed="isFullscreen.toString()"
            aria-label="Mode layar penuh" title="Mode layar penuh (Ctrl+F)"
            class="fixed bottom-4 right-4 z-40 flex h-11 w-11 items-center justify-center rounded-full bg-white border border-[#E9E9E7] shadow-sm text-[#9B9A97] hover:text-[#37352F] hover:bg-[#F7F7F5] active:scale-90 transition-all duration-200 print:hidden">
        <span class="material-symbols-rounded text-[20px]" x-text="isFullscreen ? 'fullscreen_exit' : 'fullscreen'"></span>
    </button>
</div>
