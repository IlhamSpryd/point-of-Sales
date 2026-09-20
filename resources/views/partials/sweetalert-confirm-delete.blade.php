<script>
    document.addEventListener('DOMContentLoaded', function () {
        const deleteButtons = document.querySelectorAll('.delete-button');

        deleteButtons.forEach(button => {
            button.addEventListener('click', function (e) {
                e.preventDefault();
                const formId = this.getAttribute('data-form-id');
                const form = document.getElementById(formId);

                // Perbaikan XSS: gunakan plain text, bukan HTML rendering untuk nama produk
                const itemName = this.getAttribute('data-item-name') || 'item ini';
                
                // Pastikan nama di-escape di UI dengan textContent (via parameter text SweetAlert)
                Swal.fire({
                    title: 'Apakah Anda Yakin?',
                    text: `Anda akan menghapus ${itemName}. Tindakan ini tidak dapat dibatalkan!`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Ya, hapus!',
                    cancelButtonText: 'Batal'
                }).then((result) => {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            });
        });
    });
</script>
