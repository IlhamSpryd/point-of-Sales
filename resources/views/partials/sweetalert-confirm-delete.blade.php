<script>
    // OPTIMASI SPA (wire:navigate):
    // DOMContentLoaded HANYA jalan 1x saat first load. Saat user berpindah halaman
    // lewat wire:navigate, script lama mati, script baru butuh di-bind ulang ke document.
    // Kita pakai Event Delegation global agar tidak perlu bind ulang tiap ganti halaman.
    document.addEventListener('click', function(e) {
        // Cari tombol delete (mungkin tombolnya sendiri atau icon di dalamnya)
        const deleteBtn = e.target.closest('button[data-confirm-delete="true"]');

        if (deleteBtn) {
            e.preventDefault();
            const form = deleteBtn.closest('form');

            Swal.fire({
                title: 'Apakah Anda Yakin?',
                text: "Data yang dihapus tidak dapat dikembalikan!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Ya, Hapus!',
                cancelButtonText: 'Batal',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        }
    });
</script>
