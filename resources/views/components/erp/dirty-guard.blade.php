@props([
    'watch' => null,
    'message' => 'Perubahan yang Anda buat belum disimpan. Yakin ingin meninggalkan halaman ini?',
    'isDirty' => null,
])

<div
    x-data="{
        dirty: false,
        message: @js($message),
        cleanupFn: null,
        init() {
            @if ($watch)
                this.$watch('$wire.{{ $watch }}', () => {
                    this.dirty = true;
                });
            @elseif ($isDirty)
                this.$watch(@js($isDirty), (val) => {
                    this.dirty = !!val;
                });
            @endif

            const form = this.$el.closest('form');
            if (form) {
                form.addEventListener('input', () => {
                    this.dirty = true;
                });
                form.addEventListener('submit', () => {
                    this.dirty = false;
                });
            }

            const beforeUnloadHandler = (e) => {
                if (this.dirty) {
                    e.preventDefault();
                    e.returnValue = this.message;
                    return this.message;
                }
            };

            const livewireNavigateHandler = (e) => {
                if (this.dirty) {
                    if (!window.confirm(this.message)) {
                        e.preventDefault();
                    }
                }
            };

            const resetHandler = () => {
                this.dirty = false;
            };

            window.addEventListener('beforeunload', beforeUnloadHandler);
            document.addEventListener('livewire:navigate', livewireNavigateHandler);
            window.addEventListener('erp-form-saved', resetHandler);

            this.cleanupFn = () => {
                window.removeEventListener('beforeunload', beforeUnloadHandler);
                document.removeEventListener('livewire:navigate', livewireNavigateHandler);
                window.removeEventListener('erp-form-saved', resetHandler);
            };
        },
        destroy() {
            if (this.cleanupFn) this.cleanupFn();
        }
    }"
    class="hidden"
    aria-hidden="true"
    {{ $attributes }}
></div>
