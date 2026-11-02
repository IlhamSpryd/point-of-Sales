# 12 — UI/UX Risk Register

| ID | Risiko | Fase | Likelihood | Impact | Mitigasi |
|---|---|---|---|---|---|
| RK-01 | Konsolidasi token memutus style halaman yang bergantung alias lama (`rounded-medium`, `--color-apeiron-*`) | P1 | High | High | Pertahankan alias deprecated 1 rilis; test render 14 DevDojo + visual spot-check halaman kunci; freeze deploy hari-H |
| RK-02 | Sweep hex mengubah kontras tanpa sengaja (regresi a11y) | P1–P4 | Medium | Medium | Ganti 1:1 dengan token bernilai sama; jalankan axe pada halaman tersentuh |
| RK-03 | `components:add --force` menimpa fork lokal DevDojo | kapan pun | Medium | Dokumentasikan larangan; komponen aktif dipindah kepemilikan ke app; snapshot git |
| RK-04 | Migration confirm-dialog: aksi destruktif kehilangan konfirmasi jika sweep parsial | P2 | Medium | High | Sweep atomik per modul + test yang memverifikasi dialog muncul; jangan hapus partial swal sebelum 0 referensi |
| RK-05 | SearchableSelect menambah kompleksitas & regresi keyboard di tablet kasir | P2 | Medium | Medium | Tetap sediakan select native di mode kasir jika perlu; uji pointer:coarse |
| RK-06 | Dark mode diaktifkan prematur → broken UI | P1 | Low(rekasif) | High | TH-01: tunda sampai token selesai; tambahkan test "aktifkan .dark pada 10 halaman tidak melempar kontras error" |
| RK-07 | DataTable trait menyeragamkan query → tanpa sadar membuka scope lintas-tenant | P3 | Low | **Critical** | Trait TIDAK membangun query; rows tetap dari service per-manager dengan tenant scope + test isolasi existing (LivewireTenancyIsolationTest) wajib tetap hijau |
| RK-08 | Refactor manager-shell menyentuh 30+ komponen Livewire sekaligus → blast radius besar | P3–P4 | Medium | Medium | Migrasi per-manager (1 PR per manager), mulai dari yang paling sederhana |
| RK-09 | Command palette & shortcut baru konflik dengan shortcut POS existing | P2 | Low | Low | Namespace: Ctrl+K global, POS shortcut tetap lokal container; dokumentasikan peta shortcut |
| RK-10 | Regresi performa: composed component nesting menambah render blade | P3 | Low | Benchmark render halaman tabel besar sebelum/sesudah; Livewire partial rendering tetap |
| RK-11 | Dataset demo 194k order membuat halaman laporan lambat → dicap "bug UI" | P4 | Medium | Medium | Paginate server-side wajib; async export pattern (sudah ada) diwajibkan untuk laporan baru |
| RK-12 | Kehilangan fokus operasional: redesign estetika mengalahkan stabilitas kasir | semua | Medium | High | Aturan: POS terminal flow adalah zona freeze — hanya perubahan yang lolos uji tablet nyata |
