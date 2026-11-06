<div x-data="{
    isFullscreen: false,
    toggleFullscreen() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(err => {
                console.error('Failed to enter fullscreen mode:', err);
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
    {{-- Touch button for tablet POS / kiosks. Ctrl+F shortcut remains for desktop. --}}
    <button type="button" @click="toggleFullscreen()" :aria-pressed="isFullscreen.toString()"
            aria-label="Toggle Fullscreen" title="Toggle Fullscreen (Ctrl+F)"
            class="fixed bottom-4 right-4 z-40 flex h-11 w-11 items-center justify-center rounded-full bg-card border border-border shadow-sm text-muted-foreground hover:text-foreground hover:bg-muted active:scale-90 transition-all duration-200 print:hidden">
        <span class="material-symbols-rounded text-[20px]" x-text="isFullscreen ? 'fullscreen_exit' : 'fullscreen'"></span>
    </button>
</div>
