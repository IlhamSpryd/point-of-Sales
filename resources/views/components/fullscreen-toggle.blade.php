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
    {{-- Tombol disembunyikan sesuai permintaan, fullscreen sekarang menggunakan shortcut Ctrl + F --}}
</div>
