@props([
    'id' => 'erp-confirm-dialog',
])

<div
    x-data="{
        open: false,
        title: 'Konfirmasi Tindakan',
        message: 'Apakah Anda yakin ingin melanjutkan tindakan ini?',
        confirmText: 'Ya, Lanjutkan',
        cancelText: 'Batal',
        variant: 'destructive',
        onConfirmCallback: null,
        formToSubmit: null,
        wireMethod: null,
        wireArgs: [],
        init() {
            window.confirmSubmit = (formId, itemName, options = {}) => {
                const title = itemName ? 'Hapus “' + itemName + '”?' : (options.title || 'Konfirmasi Tindakan');
                const message = options.message || options.text || 'Data yang dihapus tidak dapat dikembalikan!';
                window.dispatchEvent(new CustomEvent('erp-confirm', {
                    detail: {
                        title: title,
                        message: message,
                        confirmText: options.confirmText || 'Ya, Hapus',
                        cancelText: options.cancelText || 'Batal',
                        variant: options.variant || 'destructive',
                        formId: formId,
                    }
                }));
            };
            window.confirmDelete = (formId, itemName) => {
                window.confirmSubmit(formId, itemName);
            };
        },
        openConfirm(detail = {}) {
            const payload = Array.isArray(detail) ? detail[0] : detail;
            const opts = payload || {};
            this.title = opts.title || 'Konfirmasi Tindakan';
            this.message = opts.message || opts.text || 'Apakah Anda yakin ingin melanjutkan tindakan ini?';
            this.confirmText = opts.confirmText || opts.confirmButtonText || 'Ya, Lanjutkan';
            this.cancelText = opts.cancelText || opts.cancelButtonText || 'Batal';
            this.variant = opts.variant || 'destructive';
            this.onConfirmCallback = opts.onConfirm || null;
            this.formToSubmit = opts.formId || null;
            this.wireMethod = opts.wireMethod || null;
            this.wireArgs = Array.isArray(opts.wireArgs) ? opts.wireArgs : [];
            this.open = true;
        },
        confirm() {
            this.open = false;
            if (this.formToSubmit) {
                const form = document.getElementById(this.formToSubmit);
                if (form) {
                    form.submit();
                    return;
                }
            }
            const cb = this.onConfirmCallback;
            if (typeof cb === 'function') {
                cb();
                return;
            }
            if (this.wireMethod && this.$wire) {
                this.$wire.call(this.wireMethod, ...this.wireArgs);
            }
        }
    }"
    @erp-confirm.window="openConfirm($event.detail)"
>
    <x-ui.modal x-model="open" maxWidth="md" :id="$id">
        <x-slot:header>
            <span x-text="title" class="font-bold text-foreground"></span>
        </x-slot:header>

        <p class="text-xs sm:text-sm text-muted-foreground leading-relaxed" x-text="message"></p>

        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">
                <span x-text="cancelText"></span>
            </x-ui.button>
            <x-ui.button variant="destructive" @click="confirm()">
                <span x-text="confirmText"></span>
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
