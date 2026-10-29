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
        openConfirm(detail = {}) {
            const payload = Array.isArray(detail) ? detail[0] : detail;
            this.title = payload.title || 'Konfirmasi Tindakan';
            this.message = payload.message || payload.text || 'Apakah Anda yakin ingin melanjutkan tindakan ini?';
            this.confirmText = payload.confirmText || payload.confirmButtonText || 'Ya, Lanjutkan';
            this.cancelText = payload.cancelText || payload.cancelButtonText || 'Batal';
            this.variant = payload.variant || 'destructive';
            this.onConfirmCallback = payload.onConfirm || null;
            this.open = true;
        },
        confirm() {
            const cb = this.onConfirmCallback;
            this.open = false;
            if (typeof cb === 'function') {
                cb();
            }
        }
    }"
    @erp-confirm.window="openConfirm($event.detail)"
>
    <x-ui.modal x-model="open" maxWidth="md" :id="$id">
        <x-slot:header>
            <span x-text="title"></span>
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
