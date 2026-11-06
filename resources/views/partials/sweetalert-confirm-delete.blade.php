<script>
    document.addEventListener('DOMContentLoaded', () => {
        window.addEventListener('confirm-delete', (event) => {
            const triggerEl = document.activeElement;
            const detail = event.detail || {};
            const title = detail.title || 'Are you sure?';
            const text = detail.text || 'This action cannot be undone.';
            const confirmButtonText = detail.confirmButtonText || 'Delete';
            const cancelButtonText = detail.cancelButtonText || 'Cancel';

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: title,
                    text: text,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: confirmButtonText,
                    cancelButtonText: cancelButtonText,
                    reverseButtons: true,
                    returnFocus: true,
                    customClass: {
                        confirmButton: 'bg-destructive text-destructive-foreground hover:bg-destructive/90 px-4 py-2 rounded-md font-medium text-sm',
                        cancelButton: 'bg-muted text-muted-foreground hover:bg-muted/80 px-4 py-2 rounded-md font-medium text-sm mr-2',
                        popup: 'bg-card text-card-foreground border border-border rounded-lg shadow-lg'
                    },
                    buttonsStyling: false,
                    didClose: () => {
                        if (triggerEl && typeof triggerEl.focus === 'function') {
                            triggerEl.focus();
                        }
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        if (detail.livewireEvent) {
                            Livewire.dispatch(detail.livewireEvent, detail.params || {});
                        } else if (detail.formId) {
                            const form = document.getElementById(detail.formId);
                            if (form) form.submit();
                        }
                    }
                });
            } else if (confirm(title + '\n' + text)) {
                if (detail.livewireEvent) {
                    Livewire.dispatch(detail.livewireEvent, detail.params || {});
                } else if (detail.formId) {
                    const form = document.getElementById(detail.formId);
                    if (form) form.submit();
                }
            } else {
                if (triggerEl && typeof triggerEl.focus === 'function') {
                    triggerEl.focus();
                }
            }
        });
    });
</script>
