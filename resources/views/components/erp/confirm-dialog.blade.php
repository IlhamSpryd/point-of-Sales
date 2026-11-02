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
        init() {
            window.confirmSubmit = (formId, itemName, options = {}) => {
                const title = itemName ? `Hapus \"${itemName}\"?` : (options.title || 'Konfirmasi Tindakan');
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
            this.title = payload.title || 'Konfirmasi Tindakan';
            this.message = payload.message || payload.text || 'Apakah Anda yakin ingin melanjutkan tindakan ini?';
            this.confirmText = payload.confirmText || payload.confirmButtonText || 'Ya, Lanjutkan';
            this.cancelText = payload.cancelText || payload.cancelButtonText || 'Batal';
            this.variant = payload.variant || 'destructive';
            this.onConfirmCallback = payload.onConfirm || null;
            this.formToSubmit = payload.formId || null;
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
