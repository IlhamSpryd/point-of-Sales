{{--
  Fungsi JS untuk konfirmasi hapus data dengan SweetAlert2.
  Dipakai bersama oleh semua halaman index (Users, Roles, Categories, Products) 
  agar tidak ada script duplikat.
--}}
<script>
function confirmDelete(formId, itemName) {
    Swal.fire({
        title: 'Hapus data ini?',
        html: `Anda yakin ingin menghapus <b>${itemName}</b>? Tindakan ini tidak bisa dibatalkan.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Hapus',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#e11d48',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById(formId).submit();
        }
    });
}
</script
