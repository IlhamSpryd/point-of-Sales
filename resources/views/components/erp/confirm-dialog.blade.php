@props([
    'id' => 'erp-confirm-dialog',
])

<div
    x-data="{
        open: false,
        title: 'Confirm Action',
        message: 'Are you sure you want to proceed?',
        confirmText: 'Confirm',
        cancelText: 'Cancel',
        variant: 'destructive',
        onConfirmCallback: null,
        formToSubmit: null,
        wireMethod: null,
        wireArgs: [],
        init() {
            window.confirmSubmit = (formId, itemName, options = {}) => {
                const title = itemName ? 'Delete “' + itemName + '”?' : (options.title || 'Confirm Deletion');
                const message = options.message || options.text || 'This action cannot be undone. Are you sure you want to proceed?';
                window.dispatchEvent(new CustomEvent('erp-confirm', {
                    detail: {
                        title: title,
                        message: message,
                        confirmText: options.confirmText || 'Delete',
                        cancelText: options.cancelText || 'Cancel',
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
            this.title = opts.title || 'Confirm Action';
            this.message = opts.message || opts.text || 'Are you sure you want to proceed?';
            this.confirmText = opts.confirmText || opts.confirmButtonText || 'Confirm';
            this.cancelText = opts.cancelText || opts.cancelButtonText || 'Cancel';
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
