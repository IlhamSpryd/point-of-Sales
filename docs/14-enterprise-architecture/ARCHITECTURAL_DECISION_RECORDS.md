# Architecture Decision Records (ADR) — Paket Asesmen Enterprise

**Dokumen:** 8 dari 10 — `ARCHITECTURAL_DECISION_RECORDS.md`
**Cakupan:** Fase 12
**Tanggal:** 17 November 2026

ADR yang sudah ada (`docs/13-decisions/architecture-decision-records/ADR-001..007`) **tetap berlaku**. ADR-101+ di bawah adalah keputusan baru hasil asesmen ini. Nomor 101+ dipilih agar tidak bertabrakan.

Ringkasan status:

| ADR     | Judul                                                               | Status                             |
| ------- | ------------------------------------------------------------------- | ---------------------------------- |
| ADR-101 | Penegakan Batas Modul Melalui Manifest & Architecture Test          | Proposed                           |
| ADR-102 | Konsolidasi 24 Direktori Modul Menjadi 13 Bounded Context           | Proposed                           |
| ADR-103 | Pertahankan Shared-Database Row-Level Tenancy (Tolak DB-per-Tenant) | **Accepted (rekomendasi utama)**   |
| ADR-104 | Kanal Isolasi Tenant Eksplisit & Ratchet Bypass                     | Proposed                           |
| ADR-105 | Trigger DB & FK Komposit sebagai Kontrak Integritas                 | Accepted (mempertahankan yang ada) |
| ADR-106 | Model Uang Tunggal (`Money` minor unit + currency)                  | Proposed                           |
| ADR-107 | Batas Operasi Atomik vs Eventual (Outbox)                           | Accepted (memformalkan yang ada)   |
| ADR-108 | Application Handler per Command (Pemecahan God Service)             | Proposed                           |
| ADR-109 | State Machine Eksplisit untuk Aggregate Transaksional               | Proposed                           |
| ADR-110 | Otorisasi Berbasis Ability + Scope (Ganti Role String)              | Proposed                           |
| ADR-111 | Dekopel Siklus Finance ↔ Sales via Event & Read Contract            | Proposed                           |
| ADR-112 | Typed Configuration Registry per Scope                              | Proposed                           |
| ADR-113 | Pemisahan Entitlement (paket) dari Ability (izin user)              | Proposed                           |
| ADR-114 | Prinsip Interface: Buat Hanya Pada Seam Nyata; Hapus Wrapper        | Proposed                           |
| ADR-115 | Migration Ownership & Satu Migrasi Satu Perubahan Koheren           | Proposed                           |
| ADR-116 | Keputusan yang Secara Eksplisit DITOLAK                             | Accepted                           |
| ADR-117 | Git Hygiene: Tidak Ada Kredensial di Repo                           | **Accepted (wajib segera)**        |
| ADR-118 | Dual-Vertical Architecture (F&B / Retail / Hybrid) & Self-Serve     | **Accepted (Implemented Wave 7A)** |
| ADR-119 | OFD Omnichannel Integration (GrabFood/GoFood & Balanced PSAK GL)    | **Accepted (Implemented Wave 7C)** |

---

## ADR-101: Penegakan Batas Modul Melalui Manifest & Architecture Test

**Status:** Proposed
**Context:** `app/Modules` memiliki 750 impor lintas-modul, 0 architecture test. Dokumen `modular-monolith-architecture.md` menyatakan aturan, tetapi tidak ada mekanisme yang membuat pelanggaran gagal. Klaim "modular" tidak dapat diaudit.
**Decision:** Setiap modul memiliki `module.json` (owner, tier, dependsOn, ownsTables, publishesEvents, consumesEvents). Enam test arsitektur wajib berjalan di CI: `ModuleBoundaryTest`, `DependencyDirectionTest`, `SingleOwnerTest`, `PublicContractTest`, `NoGodClassTest`, `ModuleManifestTest`. Test berbasis parsing file (cepat, tanpa boot aplikasi).
**Alternatives:** (a) Review manual — ditolak, sudah gagal selama ini; (b) pisahkan repo per modul (microrepo) — ditolak, menambah overhead merge; (c) static analyzer kustom — lebih mahal dari test PHPUnit sederhana.
**Rationale:** murah, cepat, dapat dipahami semua engineer, dan langsung memberi umpan balik. Manifest juga menjadi sumber dokumentasi mesin.
**Trade-offs:** butuh disiplin memperbarui `module.json`; `NoGodClassTest` awalnya harus punya allowlist (ratchet) agar bisa hijau hari ini.
**Affected Modules:** semua.
**Migration:** Gelombang 1 FND-01: tulis test dengan allowlist = daftar pelanggaran saat ini; Gelombang 2 turunkan allowlist hingga 0 untuk dependency; Gelombang 3 turunkan untuk ukuran file.
**Consequences:** modul baru tidak bisa menambah ketergantungan tanpa amendemen manifest; PR review lebih cepat karena aturan jelas.
**Verification:** commit uji yang sengaja melanggar harus gagal di CI; jumlah entri allowlist tercatat menurun tiap rilis.

---

## ADR-102: Konsolidasi 24 Direktori Modul Menjadi 13 Bounded Context

**Status:** Proposed
**Context:** 24 direktori, 3 di antaranya hanya berisi shim (`Catalog` 26 file deprecated, `Restaurant` 1, `Integrations` 1); 43 basename kelas ganda; `Catalog` masih diimpor 16 kali sehingga tidak bisa dihapus sebelum migrasi impor.
**Decision:** 13 bounded context sesuai dokumen #3. Hapus shim, gabungkan `MasterData`/`Retail`/`FNB`/`CRM`/`Settings`/`Users`/`Auth` sesuai peta. `HR` → `Workforce`.
**Alternatives:** (a) biarkan 24 modul — ditolak: duplikasi otoritas membuat arch test mustahil; (b) 18 modul sesuai registry marketing — ditolak: registry adalah katalog fitur, bukan pemilik kode; (c) 6 modul besar — ditolak: terlalu kasar, `Sales` menjadi raksasa baru.
**Rationale:** 13 context dipetakan dari business capability & kepemilikan tabel, bukan dari selera; konsisten dengan tier dependensi asiklik.
**Trade-offs:** migrasi namespace menyentuh ≈430 file; risiko konflik merge; butuh urutan eksekusi ketat.
**Affected Modules:** semua (terutama Sales, Catalog, Settings, HR).
**Migration:** M1–M9 pada dokumen #3 §8, satu PR per langkah, dengan alias sementara (`class_alias`) hanya bila perlu agar rilis tetap hijau.
**Consequences:** `AGENTS.md` dan dokumen kanonikal harus diperbarui agar tidak mengarahkan ke folder deprecated.
**Verification:** `SingleOwnerTest` lulus; jumlah direktori `app/Modules` = 13; tidak ada impor ke namespace yang dihapus.

---

## ADR-103: Pertahankan Shared-Database Row-Level Tenancy (Tolak DB-per-Tenant)

**Status:** Accepted (rekomendasi utama, menggantikan asumsi "bila perlu naik kelas")
**Context:** Tim kecil; 232 tabel; 238 migrasi; 674 FK. Alternatif isolasi tanpa batas biaya operasional.
**Decision:** Satu database bersama + `tenant_id` + scope fail-closed + FK komposit. Schema-per-tenant dan database-per-tenant ditolak. Hybrid disiapkan sebagai _seam_ (bukan implementasi) dengan trigger eksplisit: tuntutan isolasi kontraktual, atau >100 jt baris `orders`, atau regulasi residensi.
**Alternatives:** lihat tabel perbandingan dokumen #4 §1.
**Rationale:** biaya migrasi × N database tidak realistis untuk ≤5 engineer; laporan lintas tenant menjadi mustahil; provisioning menjadi lambat.
**Trade-offs:** isolasi bersifat logis → wajib lint & test (ADR-104). Risiko reputasi bila terjadi kebocoran.
**Affected Modules:** Platform/Tenancy, semua modul data.
**Migration:** tidak ada perubahan skema; penambahan lint & test.
**Consequences:** harga plan tidak perlu menanggung biaya instance per tenant; enterprise khusus ditangani lewat hybrid berbayar.
**Verification:** lint bypass aktif; test ISO-01..ISO-12 lulus; `TenantSchemaInvariantTest` 100%.

---

## ADR-104: Kanal Isolasi Tenant Eksplisit & Ratchet Bypass

**Status:** Proposed
**Context:** 725 pemakaian `withoutGlobalScope(s)`; `TenantScope` fail-closed sudah benar, tetapi netralisasi scope mudah dan tanpa jejak kategori. `TransactionService` masih membaca `$data['tenant_id']` dan `$data['store_id']`.
**Decision:** (1) Ganti pemakaian bebas dengan helper bertujuan: `allBranchesInTenant()`, `forBranch($id)`, `queryForPlatformAdmin()`, `noScopeForTest()`. (2) Bypass di `app/` wajib anotasi `// @tenant-bypass: <kategori>` dengan kategori terdaftar. (3) `tenant_id`/`store_id` dari input tidak pernah dipercaya (selalu dari `TenantContext`). (4) Ratchet: jumlah bypass harus turun setiap rilis.
**Alternatives:** (a) hapus semua bypass — mustahil dalam satu rilis, dan beberapa valid (laporan owner); (b) ganti dengan repository+tenant param eksplisit — menambah abstraksi tanpa nilai; (c) Postgres RLS — engine tidak mendukung.
**Rationale:** menjaga fail-closed sambil memberi jalan sah yang terlihat dan terhitung.
**Trade-offs:** disiplin anotasi; test harus mem-parse kode.
**Affected Modules:** Sales, Reports, Settings, Platform.
**Migration:** FND-02 (helper + lint + test adversarial), FND-03 (hapus pembacaan tenant dari input).
**Consequences:** perubahan perilaku pada jalur yang selama ini mengandalkan input → wajib regression test.
**Verification:** `TenantScopeBypassTest` = 0 pelanggaran; `TenantInputTrustTest` membuktikan input `tenant_id`/`store_id` diabaikan.

---

## ADR-105: Trigger DB & FK Komposit sebagai Kontrak Integritas

**Status:** Accepted (mempertahankan & memperluas)
**Context:** 40 trigger (immutability ledger, jurnal balance, anti-overpayment, XOR) dan 674 FK komposit adalah pertahanan paling kuat di sistem ini. Namun tidak ada test yang memastikan tabel baru mengikuti pola.
**Decision:** (1) Trigger & FK dipertahankan sebagai _lapis terakhir_; logika domain tetap di service. (2) Test invariant skema: setiap tabel dengan `tenant_id` wajib `NOT NULL` + FK komposit ke induk + index berawalan `tenant_id`. (3) Template migrasi wajib untuk tabel tenant-scoped.
**Alternatives:** pindahkan semua invarian ke aplikasi — ditolak (satu jalur kode yang lupa = korupsi data); hapus trigger agar mudah dites — ditolak.
**Rationale:** data integrity lebih mahal dipulihkan daripada dicegah; trigger bekerja juga untuk import/command/migration.
**Trade-offs:** beberapa operasi maintenance (backfill, replay) harus menonaktifkan sementara dengan prosedur; trigger menambah biaya tulis kecil.
**Affected Modules:** Inventory, Finance, Sales, Purchasing, Workforce.
**Migration:** FND-04 (`TenantSchemaInvariantTest`, template migrasi).
**Consequences:** tabel baru yang melanggar langsung gagal CI.
**Verification:** test invariant hijau; `SHOW TRIGGERS` di DB bersih sesuai daftar.

---

## ADR-106: Model Uang Tunggal (`Money` minor unit + currency)

**Status:** Proposed
**Context:** `float` masih muncul di signature service (`TransactionService::calculateOrderTotals(float ...)`, `grantLoyaltyPoints(float|int)`, Livewire `public float $cashReceived`), sementara database memakai `decimal`; dua representasi (`int` rupiah & `decimal`) tanpa aturan batas modul.
**Decision:** `Money(int minor, string currency)` sebagai VO; `decimal(18,4)` untuk harga satuan; larangan `float` di signature service keuangan; `allocate()` untuk split tender tanpa sisa; rounding `HALF_UP` konsisten.
**Alternatives:** (a) tetap `int` rupiah saja — ditolak: multi-currency sudah ada (`currencies`, `exchange_rates`); (b) library eksternal `brick/money` — ditolak: menambah dependensi tanpa kebutuhan yang cukup; polyfill BCMath sudah ada.
**Rationale:** menghapus kelas bug pembulatan dan menyatukan konvensi lintas modul.
**Trade-offs:** refactor bertahap per modul; pembelajaran baru bagi tim.
**Affected Modules:** Sales, Finance, Pricing, Purchasing, Workforce.
**Migration:** Gelombang 3 bersamaan pemecahan `TransactionService`; adapter di boundary (Eloquent cast dari `decimal` → `Money`).
**Consequences:** test nominal lebih mudah & deterministik; serialisasi API wajib eksplisit (major/minor).
**Verification:** `NoFloatMoneySignatureTest`; test `allocate()` bebas sisa; rekonsiliasi setelah migrasi modul.

---

## ADR-107: Batas Operasi Atomik vs Eventual (Outbox)

**Status:** Accepted (memformalkan praktik yang sudah ada)
**Context:** Checkout sudah atomik & jurnal lewat outbox, tetapi pembagian ini tidak tertulis sebagai kontrak, sehingga mudah dilanggar.
**Decision:** Daftar eksplisit operasi yang WAJIB atomik (order+items+payments+stock+redemption+idempotency; GRN+stock; landed cost+cost history; payroll approval) dan yang WAJIB eventual (jurnal, loyalty, webhook, email/WA, cache). Larangan memanggil HTTP/SMTP di dalam transaksi DB.
**Alternatives:** semua eventual — ditolak (pengguna melihat stok salah); semua atomik — ditolak (vendor down = checkout gagal).
**Rationale:** konsistensi yang terlihat pengguna dijaga; efek samping eksternal diisolasi.
**Trade-offs:** jurnal tertunda; butuh monitoring outbox.
**Affected Modules:** Sales, Inventory, Purchasing, Finance, Integration.
**Migration:** dokumentasi + `NoHttpInTransactionTest` (scan pemanggilan gateway di dalam closure transaksi).
**Verification:** test transaksi memastikan tidak ada `Http::`/`Snap` di dalam `DB::transaction`.
**Consequences:** kode baru wajib memilih salah satu kategori secara sadar.

---

## ADR-108: Application Handler per Command (Pemecahan God Service)

**Status:** Proposed
**Context:** `TransactionService` 2.588 baris (30 method), `CreateOrder` 2.400 baris, `ReturnRefundService` 1.470, `AccountingPeriodService` 980.
**Decision:** Satu command = satu Handler di `Application/`. Handler bertanggung jawab transaksi, lock order, idempotensi, dan pemanggilan domain service. Kalkulasi murni di `Domain/Services` tanpa I/O. `TransactionService` dipecah menjadi `CheckoutOrderHandler`, `PayOnlineHandler`, `CaptureGatewayNotificationHandler`, `VoidOrderHandler`, `RestoreStockHandler`, `ShiftGuard`.
**Alternatives:** (a) biarkan, tambah test — ditolak: file tumbuh terus; (b) CQRS penuh dengan bus & query model terpisah — ditolak: kompleksitas tinggi tanpa kebutuhan; (c) pecah per agregat saja (besar) — ditolak: handler per use case lebih kecil & teruji.
**Rationale:** memperkecil blast radius; memudahkan test unit cepat (pola 142 unit test 6,93 s membuktikan nilainya).
**Trade-offs:** lebih banyak class; sedikit indirection.
**Affected Modules:** Sales (utama), Finance, Purchasing, Workforce.
**Migration:** Gelombang 3: (1) characterization test checkout/void/refund, (2) ekstrak kalkulasi murni, (3) ekstrak handler satu per satu dgn rilis bertahap, (4) hapus sisa.
**Consequences:** `NoGodClassTest` bisa diaktifkan tanpa allowlist.
**Verification:** `TransactionService` tidak ada; semua handler < 400 baris; test checkout/void/refund p95 tetap sama.

---

## ADR-109: State Machine Eksplisit untuk Aggregate Transaksional

**Status:** Proposed
**Context:** Transisi status order/payment/PO/invoice/payroll tersebar dalam `if` bertingkat dan trigger DB; sulit mengetahui transisi legal.
**Decision:** Class `StateMachine` per aggregate dengan daftar transisi legal + guard; trigger DB tetap sebagai pengaman (ADR-105); transisi selalu lewat method bernama (`markPaid()`, `void()`).
**Alternatives:** (a) enum + if (status quo) — ditolak; (b) library state machine eksternal — ditolak, cukup implementasi kecil.
**Rationale:** transisi ilegal tertolak sebelum menyentuh DB; dokumentasi terbentuk dari kode.
**Trade-offs:** refactor setiap jalur perubahan status.
**Affected Modules:** Sales, Purchasing, Finance, Workforce.
**Migration:** Bersamaan ADR-108.
**Verification:** Test semua transisi ilegal → exception domain.

---

## ADR-110: Otorisasi Berbasis Ability + Scope (Ganti Role String)

**Status:** Proposed
**Context:** Otorisasi tersebar di 4 sumber; 44/54 policy membandingkan nama role; 0 route memakai ability; 17 Livewire `abort(403)` sendiri.
**Decision:** Registry ability `module.resource.action` + scope (TENANT/COMPANY/BRANCH/SELF); `AbilityEvaluator` memeriksa subject dalam scope aktor; semua policy/route/Livewire/nav menggunakan ability; `requiresApproval` memicu approval.
**Alternatives:** (a) pertahankan role-based, tambah pengecualian — ditolak: tidak dapat menskala ke role kustom; (b) paket pihak ketiga (spatie/laravel-permission) — sebagian sudah ada (`permissions`, `role_permissions`) tetapi tidak menyelesaikan scope; gunakan tabel yang ada + evaluator kustom.
**Rationale:** satu sumber kebenaran; scoping cabang wajib untuk multi-outlet.
**Trade-offs:** migrasi mapping role → ability; risiko regresi akses.
**Affected Modules:** semua presentation + Platform/Authorization.
**Migration:** Gelombang 2 STR-08: (1) seed ability dari permission yang ada, (2) tambahkan `scope`, (3) ubah policy satu modul per PR dengan test akses, (4) hapus `RoleMiddleware` role-string & inline abort.
**Verification:** `RoleStringLintTest` = 0; `AuthorizationMatrixTest` memverifikasi matriks §4 dokumen #6.

---

## ADR-111: Dekopel Siklus Finance ↔ Sales via Event & Read Contract

**Status:** Proposed
**Context:** 13 impor Finance→Sales (`CustomerInvoice`, `ArLedgerEntry`, `ArPaymentAllocation`, enum status) dan 5 impor Customers→Sales; siklus membuat modul tidak dapat diekstrak atau diuji terpisah.
**Decision:** Finance **tidak** mengimpor model Sales. Finance menerima fakta via event (`sales.invoice.issued.v1`, `sales.order.completed.v1`) atau kontrak pembaca (`Sales\Contracts\InvoiceSnapshot`). AR ledger (milik Finance) diisi dari fakta tersebut.
**Alternatives:** (a) pindahkan AR ledger ke Sales — ditolak: double-entry satu tempat (Finance); (b) biarkan siklus — ditolak: arch test mustahil.
**Rationale:** arah dependensi menjadi asiklik: Sales → Finance (outbox), bukan dua arah.
**Trade-offs:** perubahan alur pembacaan invoice di Finance; butuh backfill.
**Affected Modules:** Finance, Sales, Customers.
**Migration:** Gelombang 2–3, per service: `ArOutboxConsumerService`, `ArGlReconciliationService`, `TaxReportService`, `Customer` model relasi.
**Verification:** `DependencyDirectionTest` lulus untuk Finance tanpa impor Sales.

---

## ADR-112: Typed Configuration Registry per Scope

**Status:** Proposed
**Context:** `settings` key-value tanpa tipe; 4 sistem pengaturan berbeda (settings, entitlements, capability, navigation roles).
**Decision:** Registry konfigurasi bertipe (key, tipe, default, validator, scope tenant/company/branch, owner module, cache key). Akses hanya via `ConfigService::get(key, scope)`.
**Alternatives:** paket settings pihak ketiga — ditolak: tidak mengatasi scope & validasi lintas modul.
**Rationale:** salah konfigurasi terdeteksi saat disimpan, bukan saat kasir gagal bayar.
**Trade-offs:** setiap key harus didaftarkan; cache invalidation wajib disiplin.
**Affected Modules:** Organization, Sales, Pricing, Integration.
**Migration:** Gelombang 4 EXT-02, migrasikan key `settings` yang sudah dipakai.
**Verification:** test menyimpan nilai invalid gagal; test invalidasi cache per scope.

---

## ADR-113: Pemisahan Entitlement dari Ability

**Status:** Proposed
**Context:** `EntitlementService::isTablesEnabled()` dipakai di service bisnis **dan** navigasi; sedangkan `hasPermission()` dipakai untuk role. Dua konsep bercampur.
**Decision:** Entitlement = "tenant membeli fitur" (paket/plan, kuota). Ability = "user boleh melakukan" (otorisasi). Middleware `enforce.entitlement` tetap untuk blokir fitur yang tidak dibeli; ability tetap menolak user yang tidak berhak. Jangan pakai salah satunya untuk keduanya.
**Alternatives:** gabungkan menjadi satu registry — ditolak: paket berubah dengan penagihan, ability berubah dengan HR.
**Rationale:** menghindari bug "Owner tidak bisa akses karena plan tidak termasuk" atau sebaliknya.
**Trade-offs:** dua pengecekan di beberapa endpoint.
**Affected Modules:** Subscription, semua presentation.
**Verification:** test bahwa plan upgrade tidak memberi akses ke ability yang tak dimiliki; downgrade menolak walau ability ada.

---

## ADR-114: Prinsip Interface & Penghapusan Wrapper

**Status:** Proposed
**Context:** 183 wrapper (model & service) tanpa manfaat substitusi; di sisi lain hanya 3 kontrak di `core` — sedikit seam nyata.
**Decision:** Interface hanya dibuat bila setidaknya satu berlaku: (a) integrasi eksternal (gateway, printer), (b) batas modul lintas context, (c) perilaku non-deterministik yang perlu diganti di test (clock, generator). Selain itu: class konkret. **Hapus** wrapper `app/Models` & `app/Services` setelah migrasi impor.
**Alternatives:** repository pattern universal — **ditolak** (ADR-116).
**Rationale:** menghindari abstraksi dekoratif, dan menghilangkan duplikasi yang membingungkan.
**Trade-offs:** migrasi impor besar; risiko regresi autoload (mitigasi: `class_alias` sementara).
**Affected Modules:** semua.
**Migration:** Gelombang 2 (M8), per modul.
**Verification:** `WrapperDeletionTest` (tidak ada file `extends` 1 baris di `app/Models`/`app/Services`); PHPStan level tetap.

---

## ADR-115: Kepemilikan Migrasi & Satu Migrasi Satu Perubahan

**Status:** Proposed
**Context:** 238 migrasi; beberapa menyatukan perubahan lintas domain (`remediate_p0_database_invariants` menyentuh shifts, loyalty, ingredient, orders trigger).
**Decision:** Migrasi berada di `Modules/<Context>/Database/Migrations` dengan nama ber-prefix modul; satu migrasi = satu perubahan koheren; tidak ada `DROP` di migrasi yang sama dengan `ADD`; idempoten; tidak memakai model Eloquent.
**Alternatives:** tetap `database/migrations` global — ditolak: kepemilikan tidak jelas & review berat.
**Rationale:** rollback & review menjadi mungkin; ownership sesuai ADR-102.
**Trade-offs:** Laravel perlu konfigurasi path migrasi tambahan (`loadMigrationsFrom`).
**Verification:** test bahwa setiap file migrasi hanya menyentuh satu tabel/kelompok terkait (heuristik review checklist, bukan otomatis).

---

## ADR-116: Keputusan yang Secara Eksplisit DITOLAK

**Status:** Accepted
**Context:** Tekanan untuk "terlihat enterprise".
**Decision & alasan:**

| Ditolak                                              | Alasan                                                                                                    |
| ---------------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| **Rewrite / greenfield baru**                        | Sistem berfungsi dengan integritas kuat; rewrite membuang 238 migrasi & 354 test; risiko komersial besar. |
| **Microservices**                                    | Transaksi kasir butuh ACID lokal; tim kecil; latensi & operasi naik. ADR-002 tetap.                       |
| **Kubernetes**                                       | 1 aplikasi, 1 DB; symlink atomic + supervisor cukup; K8s menambah beban kognitif.                         |
| **Event sourcing penuh**                             | Ledger append-only sudah memberi jejak; ES menambah kompleksitas proyeksi & migrasi event.                |
| **Repository Pattern untuk semua model**             | Eloquent sudah tepat; interface per model = dekorasi.                                                     |
| **CQRS terpisah (baca/tulis) dengan model proyeksi** | Beban tulis POS tidak memerlukan; read model parsial (snapshot/BI) cukup.                                 |
| **Database-per-tenant**                              | Biaya operasi ×N (ADR-103).                                                                               |
| **Schema-per-tenant**                                | Migrasi ×N; laporan lintas schema rumit.                                                                  |
| **Engine workflow visual bebas**                     | Membuat logika bisnis tak teruji & tak tervalidasi.                                                       |
| **Multi-region aktif-aktif**                         | Tidak ada kebutuhan residensi saat ini; konsistensi lintas region mahal.                                  |
| **NoSQL untuk transaksi**                            | Kehilangan FK/trigger/ACID yang kini menjadi kekuatan.                                                    |
| **Frontend SPA terpisah (Vue/React penuh) sekarang** | Livewire sudah produktif; migrasi ke SPA menggandakan permukaan & 52 komponen.                            |
| **Mengganti Tailwind/design system**                 | Token sudah konsisten & diaudit.                                                                          |
| **GraphQL sekarang**                                 | Tidak ada konsumen; REST cukup.                                                                           |
| **Membuat semua tabel partisi segera**               | Menambah kompleksitas sebelum ambang data tercapai.                                                       |

**Kapan ditinjau ulang:** bila muncul bukti terukur (bukan aspirasi) — mis. satu modul > 10.000 rps (microservice), tuntutan residensi (region), kebutuhan analitik > 100 jt baris (OLAP), atau tuntutan pelanggan enterprise tertentu (hybrid tenancy).

---

## ADR-117: Git Hygiene — Tidak Ada Kredensial di Repo

**Status:** Accepted — **wajib segera**
**Context:** Terverifikasi bahwa `phpunit.xml` dan `.env.testing` ter-track di git dan memuat `DB_USERNAME`/`DB_PASSWORD` plaintext.
**Decision:** (1) Rotasi kredensial DB di semua environment. (2) Parameterisasi `phpunit.xml` & `.env.testing` (`${DB_PASSWORD}`, tanpa `force`). (3) Pre-commit secret scan (gitleaks) + CI job. (4) Larang commit file berisi secret; dokumentasikan di AGENTS/Engineering Rules.
**Alternatives:** abaikan karena "repo privat" — ditolak: kontraktor/agen AI/laptop hilang = kebocoran; password sama mungkin dipakai ulang.
**Rationale:** risiko tertinggi dengan biaya remediasi terendah.
**Trade-offs:** developer harus menyiapkan env lokal (didokumentasikan).
**Verification:** `git ls-files | xargs grep -l 'DB_PASSWORD='` kosong; CI secret scan hijau; password lama tidak lagi valid.

---

## ADR-118: Dual-Vertical Architecture (F&B / Retail / Hybrid) & Owner Self-Serve Onboarding

**Status:** Accepted (Implemented Wave 7A)
**Context:** Apeiron POS melayani dua industri inti: Food & Beverage (F&B) dan Retail (serta format Hybrid). Sebelumnya konfigurasi vertikal hanya tersimpan di settings informal dan navigasi menampilkan seluruh menu ke semua merchant. Pendaftaran publik (`/register`) juga dinonaktifkan.
**Decision:**
1. Tambahkan kolom schema resmi `tenants.primary_industry` (`'fnb'`, `'retail'`, `'hybrid'`) dan `tenants.business_subtype`. Pada `stores`, tambahkan `store_vertical` dan `default_channel` (`dine_in`, `counter_retail`, dll).
2. Aktifkan kembali Owner Self-Serve Registration di `/register` dengan antarmuka Minimalist Editorial setara `/login`, dilengkapi pemilih 3 kartu vertikal (F&B, Retail, Hybrid) dan inisialisasi default pajak/service charge (F&B: PB1 10% + SC 5%; Retail: PPN 11% + SC 0%).
3. Terapkan filter vertikal pada `NavigationNode` (`verticals => ['fnb']`, `['retail']`), diuji oleh `NavigationVisibilityResolver`. Tenant F&B melihat KDS/Meja dan menyembunyikan Shelf Labels/Item Exchange; tenant Retail sebaliknya; tenant Hybrid melihat semua menu.
4. Terapkan server-side route guard `EnsureVerticalCapability` (`vertical:fnb`, `vertical:retail`) untuk menolak akses langsung via URL (fail-closed HTTP 403).
5. Adaptasi otomatis POS (`CreateOrder.php`) membaca default channel (`dine_in` vs `counter_retail`).
**Alternatives:** Menggunakan satu vertikal seragam, atau membuat kode aplikasi/repo terpisah per vertikal. Keduanya ditolak karena menambah biaya perawatan dan menghilangkan kapabilitas Hybrid.
**Rationale:** Menghadirkan UX spesifik vertikal tanpa menduplikasi codebase (tetap monolitik modular).
**Trade-offs:** Memerlukan sinkronisasi cermat antara tenant default di seeder dan test agar 0 regresi pada tes navigasi 9x19x45.
**Affected Modules:** Organization, Sales, Subscription, Platform/Navigation.
**Verification:** `tests/Feature/Vertical/Wave7DualVerticalOnboardingAndNavigationTest.php` 100% green.

---

## ADR-119: OFD Omnichannel Integration (GrabFood/GoFood Assisted Mode + HMAC Webhooks + Balanced PSAK GL Posting)

**Status:** Accepted (Implemented Wave 7C)
**Context:** Pesanan dari Online Food Delivery (OFD) seperti GrabFood dan GoFood sebelumnya dibukukan sebagai e-wallet bruto biasa tanpa mencatat beban komisi platform (20%) dan subsidi promo merchant di General Ledger. Endpoint webhook juga belum memvalidasi HMAC signature per tenant.
**Decision:**
1. Tambahkan kolom OFD pada tabel `orders` (`order_channel`, `external_order_id`, `external_short_code`, `driver_name`, `driver_phone`, `platform_commission_rate`, `platform_commission_amount`, `merchant_promo_subsidy`, `net_payout_amount`).
2. Buat tabel `ofd_channel_configs` dengan kunci rahasia HMAC terenkripsi (`signing_secret`), rate komisi, dan persentase markup.
3. Terapkan verifikasi tanda tangan kriptografis HMAC-SHA256 (`X-Grab-Signature` / `X-Gojek-Signature`) di `WebhookController` dan middleware `VerifyWebhookSignature`.
4. Sediakan POS Assisted Mode (`CreateOrder.php`) bagi kasir untuk memasukkan pesanan OFD manual secara cepat (short code driver, nama driver, nomor telepon).
5. Terapkan pola jurnal PSAK seimbang (variance Rp 0,00):
   - Step 1 (`ofd.order.completed`):
     DEBIT 1125 Piutang Kliring Platform OFD = (Gross + Tax - Promo)
     DEBIT 4195 Subsidi Promo Merchant OFD = Promo
     DEBIT 5200 Beban Komisi Platform OFD = Commission
     CREDIT 4100 Pendapatan Penjualan = Gross
     CREDIT 2200 Utang Pajak PB1/PPN = Tax
     CREDIT 1125 Piutang Kliring Platform OFD = Commission
     *(Sisa saldo akun 1125 tepat sama dengan net_payout_amount)*
   - Step 2 (`ofd.payout.settled`):
     DEBIT 1110 Bank = net_payout_amount
     CREDIT 1125 Piutang Kliring Platform OFD = net_payout_amount
     *(Menolkan akun 1125 tepat Rp 0,00)*.
**Alternatives:** Menjurnal komisi sebagai potongan harga di muka (ditolak karena melanggar PSAK/IFRS pengakuan pendapatan bruto dan beban komisi terpisah).
**Rationale:** Menjamin kepatuhan standar akuntansi keuangan PSAK, memudahkan rekonsiliasi settlement bank mingguan, dan menjaga audit trail transaksi omnichannel.
**Trade-offs:** Penambahan akun COA 1125, 4195, 5200 dan aturan posting ganda.
**Affected Modules:** Integration, Finance, Sales.
**Verification:** `tests/Feature/Integration/Wave7GrabFoodGoFoodOfdTest.php` membuktikan variance Rp 0,00 dan saldo 1125 lunas Rp 0,00.

---

## Format ADR untuk Keputusan Berikutnya

Semua ADR baru sejak sekarang wajib memakai template lengkap (Status, Context, Decision, Alternatives, Rationale, Trade-offs, Affected Modules, Migration Strategy, Consequences, Verification) seperti dokumen ini — dan **wajib** memiliki bagian _Verification_ yang dapat dieksekusi. ADR tanpa verifikasi dianggap **usulan**, bukan keputusan.
