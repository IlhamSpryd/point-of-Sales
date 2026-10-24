berikut laporan audit dan reaudit dari ke 4 claude :

# Re-Audit Domain 1: Database & Tenant Isolation (MySQL/InnoDB)

Status saya: auditor read-only, tidak mengubah file. Saya tidak mengimplementasikan perbaikan.

## 0. Batasan bukti

- **HEAD SHA, timestamp commit, dan baseline commit: NOT VERIFIABLE.** Saya tidak punya akses GitHub atau `git` di sesi ini. Saya hanya mengaudit snapshot file yang Anda lampirkan, dan tidak bisa memastikan snapshot itu sama dengan HEAD yang di-push.
- **Closure DB-001 s.d. DB-016 dinilai terhadap judul temuan di prompt.** Detail audit sebelumnya tidak tersimpan, jadi saya mengaudit independen dan membandingkan dengan deskripsi judulnya.
- **Tidak ada test yang saya jalankan.** Semua status berbasis analisis statis dan ditandai *static*. Runtime MySQL: NOT VERIFIABLE.
- **Nomor baris tidak tersedia** karena dokumen tidak bernomor. Lokasi saya tulis sebagai `Class::method` atau nama migration.
- **File yang tidak ada di snapshot:**&#x20;
  - `routes/*` (termasuk scheduler), `bootstrap/app.php`, `phpunit.xml`, `.env.example`
  - `docs/architecture/PROVISIONING.md`
  - `config/pos.php` ada, tetapi tidak memuat key `pos.system_users.*`

## 1. Ringkasan perubahan relevan Domain 1 (dari snapshot)

- Tabel provisioning sekarang dibuat oleh `2026_12_04_000001–4`. Migration `z_01–z_05` dijadikan no-op lewat guard `hasTable` dan guard baris `migrations`.
- Tabel `employee_id_sequences` dan `EmployeeIdGenerator` (UPDATE atomik dengan `LAST_INSERT_ID`) menggantikan `max(id)+1`.
- Kolom `tenants.provisioning_idempotency_key` dan `readiness_status` ditambahkan.
- Ada FK komposit `(tenant_id,user_id) REFERENCES users(tenant_id,id)` untuk token onboarding dan hasil ledger, serta unique `(tenant_id,user_id)` pada token.
- `email_outbox.body_text` memakai cast `encrypted`, plus migration `000009` untuk enkripsi data lama.
- Provisioning memakai fencing token `attempt_count` dan command `provisioning:reconcile`.
- `TenantProvisioningService` membuat 8 role, 2 system user, dan 3 setting baseline.
- `runWithoutTenant` memulihkan `isBypassed`.

## 2. Closure matrix DB-001 – DB-016

| ID                             | Status                      | Dasar singkat (static)                                                                                                                                                     |
| ------------------------------ | --------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| DB-001 Employee ID             | **FIXED** (conf. Medium)    | Alokasi atomik per `(tenant,prefix)`. Unique global `users_employee_id_unique` tetap ada, dan `tenant_id` ada di dalam nilainya. Sisa risiko: DB-018.                      |
| DB-002 System user & readiness | **PARTIALLY FIXED**         | Owner, 8 role, dan self-order user terbentuk. Email channel user tidak cocok dengan resolver (DB-017).                                                                     |
| DB-003 Kerahasiaan token       | **PARTIALLY FIXED**         | Plaintext tidak tersimpan di kolom DB oleh kode saat ini. Ada bug serialisasi (DB-019) dan jalur kebocoran non-DB (DB-020).                                                |
| DB-004 Urutan migration        | **FIXED** (static)          | Lihat §5. Sisa risiko: DB-022.                                                                                                                                             |
| DB-005 Race email              | **FIXED** (conf. Medium)    | Unique `(email, email_uniqueness_key)` menjadi otoritas final. Pemenang race menghasilkan 409. Tidak ada test race nyata.                                                  |
| DB-006 Recovery ledger         | **PARTIALLY FIXED**         | Fencing dengan satu conditional UPDATE sudah benar. Tidak ada CHECK status, `correlation_id` tidak pernah diisi, dan penjadwalan reconcile tidak terverifikasi.            |
| DB-007 Store hook & balance    | **PARTIALLY FIXED**         | Jalur provisioning benar. Hook `Store::created` tetap *silent return* bila konteks tenant tidak ada atau berbeda.                                                          |
| DB-008 Validitas test          | **OPEN**                    | Lihat §6.                                                                                                                                                                  |
| DB-009 Retensi PII             | **OPEN**                    | Hanya nonce yang punya prune. Ledger (`owner_email`), outbox (`recipient_email`, `last_error`), dan token tidak punya retensi.                                             |
| DB-010 Restorasi bypass        | **FIXED** (static)          | `runWithoutTenant` memulihkan tenant, store, dan bypass di `finally`. Sisa: `clear()` tidak mereset `isBypassed`. Tidak ada test pemulihan setelah exception atau nesting. |
| DB-011 StoreScope fail-open    | **OPEN**                    | `StoreScope::apply` tetap tidak memfilter bila `storeId === null`. Tidak ada opt-in eksplisit.                                                                             |
| DB-012 Delete & reuse email    | **OPEN (keputusan produk)** | Perilaku sudah terpetakan, lihat §7.                                                                                                                                       |
| DB-013 Nonce                   | **PARTIALLY FIXED**         | PK string, unique, dan penanganan duplikat benar. Model salah konfigurasi dan tidak ada index expiry (DB-021).                                                             |
| DB-014 `pos:verify-database`   | **OPEN**                    | Lihat DB-014 di bawah.                                                                                                                                                     |
| DB-015 FK tenant-consistency   | **PARTIALLY FIXED**         | Token dan ledger komposit. Outbox dan sequence tidak.                                                                                                                      |
| DB-016 Kolom password          | **PARTIALLY FIXED**         | Aktivasi benar. Reset password salah kolom.                                                                                                                                |

## 3. Temuan terperinci

### DB-017: Kontrak email system user tidak konsisten, readiness false-positive

- **Severity:** High | **Confidence:** High | **Status:** OPEN (baru) | **Bukti:** static
- **Lokasi:**&#x20;
  - `TenantProvisioningService::systemUserEmail`, `evaluateReadiness`
  - `SystemUserResolver::channel`
  - `ProvisionSystemUsers::handle`
  - `ProcessWebhookOrderJob::processOrder`
- **Bukti:**&#x20;
  - Provisioning membuat `channel-order.t{id}@system.local`.
  - Resolver dan command `tenant:provision-system-users` mencari `channel.t{id}@system.local`.
  - Untuk tenant 1, service memakai key config `pos.self_order_system_email` / `pos.channel_order_system_email`. Resolver memakai `pos.system_users.*`, yang tidak ada di `config/pos.php`. Dua key itu tidak sama.
  - `evaluateReadiness` memeriksa email versi service sendiri, sehingga selalu "ready".
- **Skenario:** Tenant baru lolos readiness. Webhook GrabFood/GoFood lalu memanggil `SystemUserResolver::channel`: tidak ketemu: `Log::critical` + `abort(500)`.
- **Dampak:** Tenant "ready" yang tidak bisa memproses channel order. Test provisioning tidak menangkapnya karena memakai fungsi email milik service (tautologis).
- **Remediasi:** Satu sumber kebenaran untuk nama email dan config key, dipakai bersama oleh service, resolver, dan command.
- **Acceptance:** Setelah provisioning tenant N (termasuk id 1), `SystemUserResolver::selfOrder(N)` dan `::channel(N)` mengembalikan user tanpa exception.
- **Test:** Provisioning lewat endpoint, lalu resolver dipanggil untuk tenant id 1 dan id >1.

### DB-016 (residual): Reset password menulis kolom yang tidak dibaca auth

- **Severity:** High | **Confidence:** High | **Status:** PARTIALLY FIXED | **Bukti:** static (lintas domain: auth)
- **Lokasi:** `NewPasswordController::store`, `User::getAuthPassword`, `OwnerOnboardingController::store`
- **Bukti:**&#x20;
  - Aktivasi menulis `password_hash`, dan `getAuthPassword()` membaca `password_hash ?? password`. Ini benar.
  - Reset menulis `password`. Untuk user yang sudah punya `password_hash` (semua owner hasil aktivasi), nilai itu diabaikan.
  - `RegisteredUserController` juga menulis `password` dan tidak punya `tenant_id`.
- **Dampak:** Password baru setelah reset tidak berlaku, dan password lama tetap valid.
- **Acceptance:** Setelah reset, login dengan password baru berhasil dan dengan password lama gagal.
- **Test:** Aktivasi owner: reset password: `Auth::attempt` untuk kedua password.

### DB-018: Potensi deadlock alokasi employee_id

- **Severity:** Medium | **Confidence:** Medium | **Status:** Potential (baru) | **Bukti:** static
- **Lokasi:** `EmployeeIdGenerator::next` / `ensureSequenceRow` / `allocate`
- **Bukti:**&#x20;
  - `insertOrIgnore` pada baris yang sudah ada, di dalam transaksi pemanggil, mengambil shared lock di InnoDB pada duplicate key.
  - Setelah itu `UPDATE ... LAST_INSERT_ID(last_value+1)` meminta X-lock.
  - Dua transaksi membuat user di tenant yang sama (misalnya `UserService::store` dalam `DB::transaction`, attempts=1) bisa sama-sama memegang S lalu saling menunggu X.
- **Dampak:** Error 1213 yang muncul ke pengguna. Tidak ada korupsi data. Klaim lock ini perlu dibuktikan dengan dua koneksi MySQL.
- **Remediasi:** Lakukan UPDATE dulu. Hanya jika `affected == 0`, lakukan insert-ignore lalu UPDATE ulang. Atau jamin baris sequence dibuat saat provisioning tenant.
- **Acceptance:** 20 pembuatan user paralel di satu tenant menghasilkan 0 deadlock dan 20 ID unik.
- **Test:** Concurrency test multi-proses (`Concurrency::driver('process')`) di MySQL.

### DB-019: Serialisasi `encrypt()` tidak cocok dengan cast `encrypted`

- **Severity:** Medium | **Confidence:** High | **Status:** OPEN (baru) | **Bukti:** static
- **Lokasi:** `ProcessProvisioningOutboxJob::resolvePlaintextToken` (`DB::table(...)->update(['body_text' => encrypt($body)])`) dan `handle` (update sukses dengan `encrypt($this->redactedBody())`)
- **Bukti:**&#x20;
  - Cast `encrypted` memakai enkripsi tanpa serialisasi. Helper `encrypt()` men-serialize.
  - Nilai yang ditulis lewat update mentah akan terbaca lewat cast sebagai string berformat `s:N:"…";`.
  - `ProvisioningDomainTest::test_email_failure_does_not_cancel_provisioning` membandingkan hasil cast dengan `decrypt($stored, false)`. Keduanya sama-sama membaca bentuk serialized, sehingga test tidak bisa mendeteksi bug ini.
- **Dampak:**&#x20;
  - Body email pada retry berubah menjadi string serialized dengan panjang yang salah setelah token disisipkan.
  - Tidak ada kebocoran token.
- **Remediasi:** Samakan mekanisme tulis (`Crypt::encryptString` atau lewat Eloquent). Perbaiki test agar membandingkan dengan teks asli.
- **Acceptance:** `$outbox->fresh()->body_text` setelah job sama dengan body asli.

### DB-020: Jalur kebocoran token di luar kolom DB

- **Severity:** Medium | **Confidence:** Medium | **Status:** Potential (asumsi deployment) | **Bukti:** static
- **Lokasi:** `config/mail.php`, `AppServiceProvider::boot`, `config/queue.php`, `OwnerOnboardingController`
- **Bukti:**&#x20;
  - Mailer `log` hanya diblokir saat `isProduction()`. Di staging dengan `MAIL_MAILER=log`, email plaintext masuk `laravel.log`.
  - Token ada di path URL `/onboarding/set-password/{token}`, jadi muncul di access log dan referrer.
  - Docblock job menyebut plaintext boleh ada di payload job. Dengan queue `database` (default) payload itu persisten di `jobs` dan `failed_jobs`.
  - Saat ini `dispatch` hanya mengirim `outboxId`, jadi belum bocor.
- **Remediasi:** Larang `log` mailer di semua environment non-lokal. Dokumentasikan larangan memasukkan token ke payload job. Pertimbangkan `Referrer-Policy: no-referrer` untuk halaman onboarding.

### DB-014: `pos:verify-database` memberi jaminan palsu

- **Severity:** Medium | **Confidence:** High | **Status:** OPEN | **Bukti:** static
- **Lokasi:** `VerifyDatabase::handle`, `database/sql/verify/*.sql`
- **Bukti:**&#x20;
  - Script `01–05` dan `07–09` kosong: "SKIPPED": tidak dihitung error: akhir run menampilkan "Database integrity is verified".
  - `10_security.sql` menandai FAIL untuk semua user dengan `pin_hash` atau `password_hash` NULL. Itu mencakup owner yang menunggu aktivasi dan system user.
  - Tidak ada filter `deleted_at`.
- **Dampak:** Setiap tenant baru yang sehat terbaca FAIL, sementara pemeriksaan integritas lain tidak dijalankan sama sekali.
- **Remediasi:** SKIPPED dihitung gagal. Tambah pengecualian yang eksplisit untuk owner pending-activation dan system user. Isi script FK orphan, unique key, dan konsistensi tenant.

### DB-021: Gap konsistensi tenant dan model nonce

- **Severity:** Low | **Confidence:** High | **Status:** OPEN | **Bukti:** static
- **Lokasi:** Migration `000004/000005` (`email_outbox`), `000006` (`employee_id_sequences`), `000007`, `ProvisioningNonce`
- **Bukti:**&#x20;
  - `email_outbox.tenant_id` dan `user_id` punya FK terpisah. Kombinasi tenant A dengan user tenant B tidak ditolak DB.
  - `employee_id_sequences.tenant_id` tidak punya FK ke `tenants`.
  - `tenants.provisioning_idempotency_key` tidak terikat ke `provisioning_requests.idempotency_key`.
  - `expires_at_epoch` pada nonce tidak ber-index, jadi prune melakukan full scan.
  - Model `ProvisioningNonce` tidak mendefinisikan `$primaryKey='nonce'`, `$incrementing=false`, `$keyType='string'`. `create()` tetap jalan, tetapi operasi level instance (`find`, `delete`) rusak.
- **Remediasi:** FK komposit `(tenant_id,user_id)` untuk outbox. FK ke `tenants` untuk sequence. Index `expires_at_epoch`. Perbaiki properti model.

### DB-022: Migration `000008` tidak idempoten setelah kegagalan parsial

- **Severity:** Low | **Confidence:** Medium | **Status:** Potential | **Bukti:** static
- **Bukti:**&#x20;
  - Guard `Schema::hasIndex(..., 'provisioning_requests_result_user_fk')` bergantung pada index implisit yang dibuat InnoDB dengan nama constraint.
  - Jika `dropForeign` sukses tetapi penambahan FK komposit gagal (misalnya data yatim), index implisit tetap ada.
  - Re-run akan memanggil `dropForeign` pada constraint yang sudah tidak ada dan gagal.
  - Index duplikat `(tenant_id,user_id)` juga tersisa di tabel token.
- **Remediasi:** Guard berdasarkan keberadaan *constraint* via `information_schema`, bukan nama index.

### DB-023: `isUniqueViolation` terlalu luas

- **Severity:** Low | **Confidence:** High | **Status:** OPEN | **Bukti:** static
- **Lokasi:** `ProvisioningService::isUniqueViolation`
- **Bukti:** SQLSTATE `23000` mencakup pelanggaran FK dan NOT NULL, bukan hanya duplikat (1062). Error non-unique akan di-retry dulu sebelum berakhir sebagai 500 generik.
- **Remediasi:** Cek error code driver 1062, jangan SQLSTATE saja.

### DB-024: Kesiapan tenant belum dipakai dan asumsi mesin database

- **Severity:** Informational | **Status:** OPEN | **Bukti:** static
- **Bukti:**&#x20;
  - Tidak ada kode yang membaca `tenants.readiness_status`. Tenant lama otomatis bernilai `pending` (default kolom) tanpa jalur backfill.
  - Setting baseline `tax_enabled` dan `default_sales_channel` tidak dibaca kode mana pun. Pajak memakai `config('pos.tax_rate')` global, bukan per tenant.
  - Mesin database tidak dikunci. Komentar migration merujuk dump MariaDB 10.4.32, sedangkan target audit ini MySQL.
  - Sintaks `bigint(20)`, `DROP CONSTRAINT`, dan `DROP CHECK` bergantung pada versi.
- **Aksi:** Tetapkan versi MySQL minimum di dokumentasi deployment dan CI.

### Catatan lintas domain untuk koordinator

- Pola `latest('id')+1` untuk `role_code`, `category_code`, `product_code`, dan `table_code` rawan race. Efeknya adalah error unique, bukan data korup.
- `withoutTenantScope()` hanya melepas `TenantScope`. Scope `'tenant'` milik `AssignsTenant` tetap aktif saat konteks tenant ada, sehingga "escape hatch" itu tidak benar-benar bypass.
- `Store` tidak punya scope atau trait tenant sama sekali, sehingga `Store::all()` akan bocor lintas tenant.

## 4. Penilaian skema MySQL/InnoDB

**Yang terverifikasi (static):**

- Composite FK mereferensikan unique key yang ada: `users_tenant_id_id_unique`, `stores_tenant_id_id_unique`, dan `*_tenant_id_id_unique` di tabel produk. Urutan kolom konsisten `(tenant_id, id)`.
- Tipe kolom FK cocok (`unsignedBigInteger`).
- `ON DELETE SET NULL` hanya dipakai pada kolom nullable.
- Unique email aktif memakai generated column virtual: aktif bernilai 0, soft-deleted NULL. Ini benar di MySQL.
- Unique `(tenant_id, idempotency_key)` pada `orders` menggantikan unique global.

**Catatan:** transaksi dan locking hanya diasumsikan InnoDB. Tidak ada `ENGINE` eksplisit, dan `engine => null` mengikuti default server.

## 5. Fresh install vs upgrade

- **Fresh install:** Urutan lexical: `0001_*`: `2026_*`: `2026_12_04_000001–9`: `z_01–z_05`. Prefix `z_` (0x7a) sorting setelah `2` (0x32), jadi `z_*` jalan terakhir. `000001–4` membuat tabel. `000005` menambah FK (prasyarat unique `users(tenant_id,id)` sudah ada dari `2026_10_09_000000`). `000006–9` menambah sequence, kolom, dan hardening. Semua `z_*` lalu melompat. Tidak ditemukan alter-sebelum-create.
- **Upgrade, `z_*` sudah pernah jalan:** `000001–4` melompat lewat guard `hasTable`/baris `migrations`. `000005` melompat karena guard nama `z_05_...` cocok dengan nama file asli. `000006–9` jalan sebagai penambahan.
- **Kesetaraan:** Definisi kolom dan nama FK `000001–5` identik dengan `z_01–05`. Kedua jalur berakhir di skema yang sama (analisis statis, belum dibuktikan dengan diff `information_schema`).
- **Peringatan:** Mengganti nama atau menghapus migration yang sudah ter-deploy merusak riwayat. Guard saat ini bergantung pada nama string di tabel `migrations`, jadi nama file tidak boleh diubah lagi.

## 6. Kondisi test (DB-008)

**Test yang ada tapi lemah:**

- `test_parallel_requests_same_key...` hanya menguji state machine, bukan proses paralel.
- `StockBalanceDeadlockTest` sekuensial dalam satu koneksi. Itu tidak menguji deadlock.
- Test employee ID sekuensial.
- Test readiness tautologis terhadap `systemUserEmail`.
- `test_unrelated_query_exception_propagates` menegaskan 500 yang ditangani, bukan propagasi, dan `Tenant::flushEventListeners()` bisa bocor antar test.
- `TransactionConcurrencyTest` (satu-satunya suite chaos nyata) berstatus `.disabled`.

**Test yang cukup bermakna:**

- FK ledger tenant/user (`test_ledger_result_user_must_belong_to_result_tenant`).
- Fencing pada `finaliseLedger` (`test_completed_cannot_be_overwritten...`).
- Isolasi cross-tenant `BaselineTest` T1–T9, `TenantScopedUniqueTest`, dan `Settings` isolation. Isolasi baca/tulis lintas tenant cukup tercakup di sana, tetapi belum dijalankan.

**Tidak ada:** test kesetaraan migration fresh vs upgrade, test race email nyata, test restorasi bypass saat exception, dan test retensi PII.

**Eksekusi:** tidak ada yang saya jalankan, dan `phpunit.xml` tidak tersedia. Runtime MySQL: NOT VERIFIABLE.

## 7. Temuan kode vs asumsi deployment vs keputusan produk

- **Kode (confirmed static):** DB-017, DB-016 (reset), DB-019, DB-014, DB-021, DB-023.
- **Potensial / perlu runtime:** DB-018, DB-022.
- **Asumsi deployment:** DB-020, DB-024 (versi mesin, scheduler `provisioning:reconcile` dan `provisioning:prune-nonces`).
- **Keputusan produk (DB-012):**&#x20;
  - Email global satu akun = satu tenant. Satu orang tidak bisa menjadi owner dua tenant.
  - Soft-delete user membebaskan email. Soft-delete tenant tidak membebaskan email user-user-nya. Tidak ada jalur pemulihan atau pelepasan email.
  - `ProfileController::destroy` memungkinkan owner menghapus akunnya sendiri sehingga tenant bisa tanpa owner.
  - Perlu keputusan kebijakan sebelum production.

## 8. Blocker

**Sebelum merge:**

1. DB-017: samakan kontrak email dan config key system user, plus test resolver.
2. DB-016: perbaiki jalur reset password, plus test login.
3. DB-019: samakan mekanisme enkripsi outbox, plus test yang membandingkan teks asli.
4. DB-014: hentikan pesan "verified" jika ada script kosong, dan perbaiki false-FAIL `10_security`.

**Sebelum production:**

- DB-018: uji deadlock sequence dengan MySQL nyata.
- DB-011: opt-in eksplisit untuk cross-store, dan kebijakan jika `storeId` null di job/CLI.
- DB-009: kebijakan dan job retensi PII (ledger, outbox, token).
- DB-020: larang mailer `log` di semua environment non-lokal.
- Keputusan produk DB-012.
- Penguncian versi MySQL dan verifikasi scheduler.

## 9. Bukti yang masih kurang

- SHA HEAD dan baseline, dari `git log -2` dan `git diff <baseline>..HEAD -- app/ database/ tests/`.
- Hasil `php artisan test --group=tenancy-baseline` dan `--filter=ProvisioningDomainTest` di MySQL nyata, dengan versi MySQL tercatat.
- Dump `information_schema` untuk tabel provisioning dari fresh install dan dari upgrade, lalu di-diff.
- `routes/console.php`, `bootstrap/app.php`, `phpunit.xml`, `.env.example`, dan `docs/architecture/PROVISIONING.md`.
- Bukti dua koneksi untuk DB-018 (`SHOW ENGINE INNODB STATUS` setelah dua insert paralel).

## 10. Verdict

**FAIL** untuk state saat ini.

Fondasi isolasi (composite FK, unique per tenant, scope, dan restorasi konteks) terlihat sehat secara statis. Namun ada tiga defect terkonfirmasi yang memblokir: tenant "ready" yang tidak bisa memproses channel order (DB-017), reset password yang tidak efektif (DB-016), dan body email outbox yang rusak pada retry (DB-019). Ditambah pemeriksaan integritas yang memberi jaminan palsu (DB-014) dan tidak adanya satu pun test yang dijalankan terhadap MySQL.

Setelah blocker merge 1–4 diperbaiki dan test dijalankan di MySQL, saya perkirakan verdict naik menjadi **PASS WITH CONDITIONS**.



# Re-audit Domain 1: Provisioning API & Security Protocol (read-only)

## 0. Metadata dan batasan bukti

| Item                                      | Nilai                                                                                                                                                                                                                                                        |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| HEAD GitHub (`IlhamSpryd/point-of-Sales`) | `1808e537523084d3bb2c046cc246a903355525fa`, 2026-10-11T11:36:20+07:00, "feat(config): configure provisioning lease timeouts and employee ID prefix"                                                                                                          |
| Baseline audit sebelumnya                 | **Tidak diberikan.** Saya asumsikan `17205c41` (2026-10-10 21:46, implementasi awal provisioning). Kalau SHA baseline Anda berbeda, kolom "perubahan" di bawah perlu dibaca ulang.                                                                           |
| Commit sejak baseline                     | `5ed99122` (hardening ledger/token/migration, 11:30) dan `1808e537` (hanya `config/pos.php`, +9 baris)                                                                                                                                                       |
| Kode committed vs lokal                   | Saya clone HEAD bersih (`git status` kosong) dan mengutip nomor baris dari clone itu. File yang Anda lampirkan konsisten dengan HEAD pada semua titik yang saya cek, termasuk config lease dari commit terakhir. Diff byte-per-byte tidak bisa saya lakukan. |
| Test                                      | **Tidak ada test yang saya jalankan.** Sandbox tidak memiliki `php`, `composer`, atau `mysql`, dan egress tidak mencakup packagist. Semua status memakai bukti **static inspection**. Klaim runtime berstatus NOT VERIFIABLE.                                |
| Target DB                                 | Hanya MySQL/InnoDB. CI memakai `mysql:8.0`, tetapi hasilnya tidak mengikat (lihat SEC-019).                                                                                                                                                                  |
| SEC-005 dan SEC-009                       | Tidak ada di daftar permintaan, jadi tidak dinilai.                                                                                                                                                                                                          |

Perubahan yang relevan sejak baseline:

- Ledger kini punya `attempt_count` sebagai fencing token, `lease_expires_at`, dan command `provisioning:reconcile`.
- Token onboarding disimpan sebagai hash, ada `reissueFor()` untuk rotasi, dan ada unique `(tenant_id, user_id)`.
- Outbox memakai klaim atomik dan `body_text` terenkripsi.
- Catch nonce berubah dari string-match `Duplicate entry` menjadi `getCode() === '23000'`.
- Ada `TenantProvisioningService`, `EmployeeIdGenerator`, dan migration 006–009.

## 1. Matriks penutupan

| ID                      | Status                                        | Alasan singkat (bukti HEAD)                                                                                                                                                                                                                                                                                                                                                |
| ----------------------- | --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| SEC-001 Nonce atomik    | **FIXED (static)**, residual Low              | PK `nonce string(64)` di `2026_12_04_000002`. Insert tunggal di `VerifyProvisioningSignature.php:115`, nonce hanya dicatat setelah signature valid. Catch `:121` memakai SQLSTATE `'23000'` (benar untuk MySQL, `getCode()` bertipe string). Residual: `23000` juga mencakup NOT NULL dan FK, jadi sebaiknya `errorInfo[1] === 1062`. Tidak ada test race konkuren.        |
| SEC-002 Ledger stuck    | **PARTIALLY FIXED**                           | `claim()` (`ProvisioningService.php:284`) memakai conditional UPDATE pada status + `attempt_count`. `finaliseLedger()` (`:358`) memakai fencing. Ada lease 10 menit dan reconcile tiap 5 menit. Kegagalan permanen vs transient dibedakan. Sisa: SEC-018, SEC-014, dan dua test fencing yang tidak membuktikan apa pun (SEC-010).                                          |
| SEC-003 Token plaintext | **PARTIALLY FIXED**                           | Hash-only di DB, plaintext tidak masuk queue (`dispatch` di `:246` hanya mengirim id), `safeError` tidak membawa pesan mentah. Sisa: SEC-015, SEC-016, SEC-017.                                                                                                                                                                                                            |
| SEC-004 HTTPS/proxy     | **OPEN (kode) / NOT VERIFIABLE (deployment)** | Tidak ada cek `isSecure()`, `forceScheme`, `forceRootUrl`, atau `TrustHosts` (grep kosong). `PROVISIONING.md` hanya mendokumentasikan URL https. `bootstrap/app.php` mempercayai `env('TRUSTED_PROXIES','127.0.0.1')` termasuk `X_FORWARDED_HOST/PROTO`, sementara `.env.example:78` berisi `*`.                                                                           |
| SEC-006 Magic link      | **PARTIALLY FIXED**                           | Format link sudah cocok dengan route `GET/POST /onboarding/set-password/{token}` (web.php:50). Token di email berupa plaintext 48 karakter, di DB berupa hash. Aktivasi men-set `password_hash` dan `email_verified_at`, lalu redirect ke `login`. `Auth::attempt` secara statis cocok dengan `getAuthPassword()`. Sisa: tidak ada test e2e login dan SEC-017.             |
| SEC-007 Rate limit      | **PARTIALLY FIXED**                           | `throttle:10,1` pada provisioning dan `throttle:6,1` pada show/store onboarding. Keduanya per-IP dan berjalan sebelum verifikasi signature. Lihat catatan di bagian 2.                                                                                                                                                                                                     |
| SEC-008 Email squatting | **OPEN**                                      | Tiga sub-masalah, dirinci di bagian 2.                                                                                                                                                                                                                                                                                                                                     |
| SEC-010 Validitas test  | **OPEN**                                      | Banyak test menyesatkan, plus CI non-gating (SEC-019).                                                                                                                                                                                                                                                                                                                     |
| SEC-011 Kontrak error   | **PARTIALLY FIXED**                           | `shouldRenderJsonWhen(api/*)` sudah ada, jadi 422 berupa JSON, bukan redirect. Body 401/500 generik dan tidak membocorkan detail. Sisa: nonce-replay memakai 409 dengan `error: unauthorized_request`, status "pending" memakai HTTP 200, dan tidak ada guard `APP_DEBUG` untuk production (`.env.example` bernilai `true`).                                               |
| SEC-012 Validasi/limit  | **PARTIALLY FIXED**                           | Query string ditolak. Body ≤32 KB (dicek setelah body dibaca, jadi batas sebenarnya ada di web server). Timestamp `ctype_digit`, nonce regex ≤64, key idempotency 16–64, dan field allowlist. Sisa: Content-Type tidak dipaksa, regex key memakai `$` bukan `\z`, dan SEC-021.                                                                                             |
| SEC-013 Collation nonce | **FIXED**, residual Low                       | `string(64)` PK dengan collation default `utf8mb4_unicode_ci`. Efeknya case-insensitive, sehingga hanya bisa memberi **false collision**. Tidak ada bypass replay karena signature mengikat nonce persis. Rekomendasi: `utf8mb4_bin` untuk `nonce` dan `idempotency_key`.                                                                                                  |
| XD-01 Kelengkapan       | **PARTIALLY FIXED**                           | Lihat SEC-024 di bawah.                                                                                                                                                                                                                                                                                                                                                    |
| XD-02 TenantContext     | **PARTIALLY FIXED**                           | Lihat bagian 2.                                                                                                                                                                                                                                                                                                                                                            |
| XD-03 Employee ID       | **FIXED**, tanpa risiko authz                 | `allocate()` memakai `UPDATE … LAST_INSERT_ID(last_value+1)`, nilainya memuat `tenant_id`, dan PK sequence `(tenant_id, prefix)`. Satu hal informasional: backfill memakai segmen numerik terakhir, jadi tenant yang ID-nya sama dengan tahun legacy (misalnya 2026) bisa bentrok. Itu gagal keras di unique index, bukan korupsi diam-diam. Detail schema untuk Claude 1. |
| XD-04 Outbox            | **PARTIALLY FIXED**                           | Klaim atomik (conditional UPDATE + `attempts` sebagai fencing, lease lewat `next_attempt_at`). Garansinya at-least-once, jadi email ganda hanya mungkin bila crash setelah kirim. Rotasi token berjalan dalam transaksi bersama update body. Sisa: SEC-015 dan SEC-016.                                                                                                    |
| XD-05 Webhook           | **Catatan lintas-domain saja**                | `VerifyWebhookSignature` hanya memakai skew 5 menit tanpa nonce store. HMAC disusun dari `body.timestamp` tanpa delimiter. `abs(time() - $timestamp)` tidak memvalidasi format. Untuk audit payment.                                                                                                                                                                       |

## 2. Temuan

Tidak ada temuan Critical atau High. Tingkat tertinggi adalah Medium.

### Code-level

**SEC-014. `X-Idempotency-Key` (dan Host) tidak ter-sign — Medium, Confidence High, OPEN**

- Lokasi: canonical di `VerifyProvisioningSignature.php:79–85` hanya mencakup METHOD, PATH, TS, NONCE, dan hash body. Header dibaca di `ProvisioningController.php:29`.
- Skenario: pihak di jalur (proxy yang men-terminate TLS, atau log yang bocor) bisa mengganti key tanpa merusak signature. Mengganti ke key milik request lain dengan payload berbeda menghasilkan 409 dan membakar nonce, sehingga registrasi sah gagal.
- Dampak: integritas idempotency tidak terikat ke autentikasi (defense-in-depth). Email unik tetap mencegah tenant ganda, jadi dampaknya integritas/DoS, bukan duplikasi.
- Rekomendasi: masukkan `X-Idempotency-Key` (dan idealnya host/audience) ke canonical. Ini perubahan protokol, jadi sinkronkan dengan situs marketing dan `PROVISIONING.md`.
- Acceptance: request dengan key yang diubah menghasilkan 401 `unauthorized_request`.
- Test: tampered idempotency header: 401, tanpa nonce tercatat.

**SEC-015. Format enkripsi outbox tidak konsisten — Medium, Confidence Medium (belum dieksekusi), OPEN**

- Lokasi: `ProcessProvisioningOutboxJob.php:89` dan `:178` memakai `encrypt($body)`. Helper itu men-serialize secara default. Model `EmailOutbox` memakai cast `encrypted`, dan migration `…000009` memakai `decrypt($raw,false)` dan `Crypt::encryptString`, yaitu tanpa serialize.
- Skenario: setelah rotasi token atau pengiriman sukses, baris tersimpan dalam format serialized. Saat dibaca lewat model, `body_text` berisi wrapper `s:N:"…";`. Pada retry, email yang terkirim berisi sampah wrapper itu.
- Dampak: email onboarding korup pada jalur retry, dan enkripsi at-rest tetap terjadi tetapi dengan format salah. Ini integritas, bukan kebocoran.
- Test yang menyembunyikannya: `test_email_failure_does_not_cancel_provisioning` (`:580`) membandingkan `$outbox->body_text` dengan `decrypt($storedBody,false)`. Keduanya sama-sama berformat serialized, jadi test lolos walau datanya korup.
- Rekomendasi: gunakan `Crypt::encryptString` pada semua update query-builder (atau satu helper tunggal).
- Acceptance: round-trip body setelah rotasi dan setelah sukses terbaca persis sama lewat model.

**SEC-016. Guard mailer `log` bisa dilewati lewat `failover` — Medium, Confidence High, OPEN**

- Lokasi: `AppServiceProvider.php:46` hanya menolak `config('mail.default') === 'log'`. `config/mail.php` mendefinisikan mailer `failover` = `[smtp, log]`.
- Skenario: production dengan `MAIL_MAILER=failover`. Saat SMTP gagal, email berisi plaintext magic link tertulis ke log.
- Rekomendasi: tolak juga `failover` atau `roundrobin` yang memuat `log` di production. Pertimbangkan pengecualian eksplisit untuk transport `log`/`array`.
- Test: boot production dengan `failover`: exception.

**SEC-017. Host dan scheme magic link diambil dari request — Medium, Confidence Medium, OPEN**

- Lokasi: `ProvisioningService.php:663` memakai `route('onboarding.set-password', …)` di dalam request HTTP. Tidak ada `forceRootUrl`, `forceScheme`, atau `TrustHosts`. `bootstrap/app.php` mempercayai `X_FORWARDED_HOST` dan `X_FORWARDED_PROTO`.
- Skenario: `Host` dan `X-Forwarded-*` tidak ter-sign. Dengan `TRUSTED_PROXIES=*` atau pihak di jalur, link yang dikirim ke Owner bisa menunjuk domain lain, sehingga token bocor saat diklik. Syaratnya request harus lolos HMAC, jadi ini bukan eksploitasi anonim.
- Rekomendasi: bangun URL dari `config('app.url')` (di sisi aplikasi) dan tetapkan allowlist host/proxy.
- Test: request dengan `X-Forwarded-Host` asing menghasilkan link dengan host `APP_URL`.

**SEC-018. Email milik user soft-deleted: respons 500 "retryable" berulang — Medium, Confidence Medium-High, OPEN**

- Lokasi: `ProvisioningService.php:135` dan `:169` memakai `User::where('email')->first()` (User memakai `SoftDeletes`). `users.email` unik global (`0001_01_01_000000_create_users_table.php:14`).
- Skenario: email user yang sudah soft-delete lolos pre-check, lalu INSERT memicu unique violation. Cek konflik tidak menemukan baris, jadi dianggap transient, retry 2×, lalu 500 `provisioning_failed` dengan `retryable: true`. Klien mencoba terus tanpa akhir.
- Rekomendasi: gunakan `withTrashed()` dan perlakukan sebagai 409 `email_already_in_use` (atau putuskan kebijakan pemulihan email).
- Test: soft-deleted owner: 409 permanen.

**SEC-019. CI tidak menggagalkan build saat test gagal — Medium (proses), Confidence High, OPEN**

- Lokasi: `.github/workflows/tests.yml:94` dan `:96`, `php artisan test … || true` (suite utama dan suite chaos).
- Dampak: tidak ada bukti otomatis bahwa test Domain 1 hijau pada MySQL. Mengganggu gate "no merge tanpa bukti".
- Rekomendasi: hapus `|| true`, dan pertahankan `--min` coverage.

**SEC-010. Test yang tidak membuktikan klaimnya — OPEN (detail)**

- `test_tenant_context_restored_after_exception` (`:385`): payload tidak konflik, provisioning sukses, jadi exception path tidak pernah dijalankan.
- `test_completed_cannot_be_overwritten_by_stale_worker_finalization` (`:650`): lolos hanya karena status sudah `completed`. Fencing `attempt_count` tidak diuji.
- `test_stale_worker_cannot_complete_or_fail…` (`:672`): menulis ulang SQL di dalam test, bukan memanggil kode produksi.
- `test_magic_link_url_from_outbox_is_valid…` (`:498`): tidak membaca outbox. Token diambil lewat `reissueFor` dari DB.
- `test_email_failure_…` (`:580`): tautologi (lihat SEC-015) dan memakai mock `Mail`.
- `test_parallel_requests_…` (`:263`): hanya state-machine, bukan paralel. `test_rejects_replayed_nonce` (`:202`) sekuensial.
- `test_error_responses_do_not_leak_…` (`:754`) hanya mencakup jalur 401. `test_unrelated_query_exception_propagates` (`:732`) memasang listener `creating` global yang baru dibersihkan setelah assertion, sehingga bisa bocor ke test lain bila assertion gagal.
- Tidak ada: test login Owner setelah aktivasi, replay konkuren nyata, rotasi + expiry previous secret, Content-Type salah, dan payload malformed (array/objek) pada field string.

**SEC-020. PII di log — Low, Confidence High**

- `ProvisioningService.php:204` memasukkan `errorInfo[2]` atau `getMessage()` ke log, yang bisa berisi email (`Duplicate entry 'x@y' …`). `runWithoutTenant` juga menulis warning + backtrace pada setiap provisioning.
- Rekomendasi: log kode error saja dan redaksi email.

**SEC-021. `\s` pada regex nama memperbolehkan CR/LF — Low, Confidence Medium**

- `StoreProvisioningRequest.php:27`. `tenant_name` masuk ke Subject dan body email dan disimpan di DB.
- Saya tidak mengonfirmasi bahwa Symfony Mime bisa disalahgunakan. Hardening saja: ganti `\s` dengan `[^\S\r\n]` atau strip control chars.

**SEC-022. Secret previous tanpa batas waktu dan tanpa telemetri — Low, Confidence High**

- `activeSecrets()` (`:134`) menerima `previous` selama terisi. Tidak ada log yang menyebut secret mana yang cocok dan tidak ada validasi panjang minimum secret.
- Rekomendasi: log "matched=current|previous", alert bila `previous` masih dipakai setelah jendela rotasi, dan tolak secret <32 byte di production.

**SEC-024 (XD-01). Kesiapan tenant "ready" tetapi system user tidak ditemukan resolver — Medium, Confidence Medium-High, OPEN, koordinasi Claude 4**

- `TenantProvisioningService::systemUserEmail()` (`:86`) selalu menghasilkan `selforder.t{id}@system.local`. `SystemUserResolver.php:13` dan `:29` untuk tenant ID 1 mencari `config('pos.system_users.*')`, kunci yang tidak ada di `config/pos.php`, sehingga memakai default `selforder@system.local` dan `channel@system.local`.
- Skenario: pada DB baru, tenant pertama dari API mendapat ID 1. Provisioning mengembalikan 201 dan `readiness_status='ready'`, tetapi self-order dan webhook channel akan `abort(500)`.
- Test sirkular: tes provisioning mengambil email dari `systemUserEmail()` itu sendiri.
- Juga: `refreshReadiness()` hasilnya tidak ditampilkan di respons 201, dan exception-nya ditelan.

### Deployment assumptions

- HTTPS, HSTS, dan proxy tepercaya tidak diverifikasi oleh kode (SEC-004). `TRUSTED_PROXIES` default aman (`127.0.0.1`), tetapi `.env.example` memakai `*`. Dengan `*`, `X-Forwarded-For` bisa dipalsukan. Bucket `throttle` provisioning milik caller sah bisa dihabiskan (DoS) dan limit onboarding bisa dilewati. Entropi token 48 karakter membuat brute force tidak realistis.
- Batas ukuran body dan Content-Type harus ditegakkan di web server.
- Scheduler wajib berjalan (`provisioning:prune-nonces`, `provisioning:reconcile`, `emails:process-outbox`).
- Production wajib `APP_DEBUG=false`, mail transport nyata, dan secret ≥32 byte yang berbeda per environment (host tidak ter-sign, jadi secret yang sama di staging dan prod bisa lintas-environment).
- Collation server nyata tidak bisa diverifikasi dari kode.

### Product decisions (bukan defect murni)

- **SEC-008, tiga sub-masalah:**&#x20;
  1. Tidak ada bukti kepemilikan email sebelum tenant dibuat. Pihak lain bisa mendaftarkan email korban. Korban menerima email Apeiron berisi nama bisnis pilihan penyerang, dan email itu terkunci global karena `users.email` unik.
  2. Token kedaluwarsa 48 jam: tidak ada route resend. `reissueFor` hanya bekerja untuk token yang belum kedaluwarsa. Hasilnya email terkunci permanen (409) sampai ada intervensi support.
  3. 409 `email_already_in_use` menjadi oracle enumerasi bila situs marketing meneruskannya ke pengunjung.
  - Keputusan yang perlu diambil: verifikasi email di marketing sebelum memanggil API, atau kebijakan reclaim/resend untuk tenant yang tidak diaktivasi.
- XD-02 (nested context): `runAs()` di dalam `runWithoutTenant()` membiarkan `isBypassed=true` (`TenantContext.php:139`). `TenantScope` tetap aktif karena `hasTenant()`, tetapi `StoreScope` (`StoreScope.php:22`) dan `DiscountStoreScope` melewati filter selama blok itu. Dalam provisioning ini hanya membuat data, jadi risikonya terbatas. Pemulihan `finally` terverifikasi secara statis. Jangan memakai pola ini untuk jalur baca.
- Migration ganda: set `z_01..z_05` dan set `2026_12_04_*` sama-sama membuat tabel yang sama dengan guard `hasTable`. Urutan nama membuat set `2026_` jalan lebih dulu. Dua sumber kebenaran schema berpotensi drift (detail untuk Claude 1; saya belum mendiff isi keduanya sepenuhnya).

## 3. Blocker

**Sebelum merge**

1. SEC-019: CI harus fail pada test gagal, dan test Domain 1 dijalankan di MySQL dengan hasil dilampirkan.
2. SEC-015: perbaiki format enkripsi dan buktikan lewat round-trip test.
3. SEC-016: perluas guard mailer.
4. SEC-024: selaraskan email system user tenant 1 dengan resolver (koordinasi Claude 4).
5. SEC-010: ganti test tautologis dengan test yang memanggil jalur produksi.

**Sebelum production**

1. SEC-014 (sign idempotency key), SEC-017 (URL dari `APP_URL`), dan SEC-018 (`withTrashed`).
2. SEC-004 dan SEC-007: HTTPS/HSTS, `TRUSTED_PROXIES` spesifik, `APP_DEBUG=false`, dan batas body di web server.
3. Keputusan produk SEC-008 (verifikasi email atau resend).
4. SEC-022: runbook rotasi secret dan alert pemakaian `previous`.
5. Scheduler aktif dan dipantau.

## 4. Bukti yang masih kurang

- Hasil `php artisan test` pada MySQL 8 (perintah, versi, skipped, dan durasi).
- Bukti runtime bahwa `encrypt()` di job dan cast `encrypted` menghasilkan format berbeda (satu test round-trip cukup).
- Konfigurasi deployment: nginx/LB (TLS, `client_max_body_size`, header forward), nilai `TRUSTED_PROXIES`, `APP_URL`, dan collation DB.
- Kode situs marketing (apakah ia memverifikasi email, dan bagaimana ia menandatangani request).
- SHA baseline audit sebelumnya, dan diff isi `z_*` vs `2026_12_04_*`.

## 5. Verdict: **PASS WITH CONDITIONS**

Inti protokol sudah sehat. HMAC dengan canonical method+path+timestamp+nonce+hash body, perbandingan `hash_equals`, nonce atomik di PK MySQL, ledger berfencing, token hash-only, dan respons error generik semuanya terverifikasi secara statis. Namun tidak ada bukti eksekusi test, CI tidak mengikat, dan ada lima temuan Medium yang diurutkan di atas. Status naik ke PASS setelah blocker "sebelum merge" tertutup dan hasil test MySQL terlampir.



# Audit Domain 1 (Transaction Integrity & Idempotency): Apeiron System

## 0. Batasan audit

| Item                                                       | Status                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| ---------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Commit SHA / snapshot ID                                   | **TIDAK TERSEDIA.** Hanya dokumen teks yang dilampirkan, tanpa metadata `.git`, `git status`, atau diff. Tidak dapat dipastikan apakah kode sudah di-commit atau masih perubahan lokal. Komentar kode bertanggal sampai 2026-09-25.                                                                                                                                                                                                                                    |
| Nomor baris                                                | Tidak tersedia, jadi bukti dirujuk per file dan method.                                                                                                                                                                                                                                                                                                                                                                                                                |
| Test dijalankan                                            | **TIDAK DIJALANKAN.** Tidak ada checkout repo, database MySQL, atau shell dengan dependensi di sesi ini. Semua temuan di bawah adalah analisis statis.                                                                                                                                                                                                                                                                                                                 |
| File dari daftar wajib yang **NOT FOUND / tidak disuplai** | `routes/console.php`, seluruh `database/migrations/`, `config/database.php`, `config/queue.php`, `config/pos.php`.                                                                                                                                                                                                                                                                                                                                                     |
| File yang diperiksa                                        | `ProvisioningService`, `TenantProvisioningService`, `EmployeeIdGenerator`, `ProvisioningRequest`, `ProvisioningNonce`, `OwnerOnboardingToken`, `EmailOutbox`, `ProcessProvisioningOutboxJob`, `ProcessEmailOutbox`, `ReconcileProvisioningLedger`, `User`, `TenantContext`, `ProvisioningController`, `VerifyProvisioningSignature`, `OwnerOnboardingController`, `ProvisioningDomainTest`, plus hook model terkait (`Role`, `Store`, `Category`, `Product`, `Table`). |

Karena migration tidak ada, semua pertahanan berbasis constraint (unique index, composite FK, trigger) berstatus **Unable to Verify**.

Label confidence yang dipakai:

- **Confirmed (statis)**: terbukti dari kode, tidak dieksekusi.
- **Conditional**: bergantung pada konfigurasi atau skema yang belum terlihat.
- **Unable to Verify**: bukti tidak ada.

## 1. Verdict ringkas

**Transaction integrity & idempotency Domain 1: FAIL (belum terbukti).**

Desain intinya sehat. Klaim ledger dan klaim outbox dilakukan lewat conditional UPDATE. Finalisasi ledger memakai fencing token. Pemulihan crash memakai penanda idempotency yang ikut commit bersama tenant. Penulisan sequence employee ID berada di dalam transaksi pemanggil.

Verdict tetap FAIL karena tiga alasan:

1. Ada satu cacat Confirmed (statis) yang membuat email aktivasi bisa hilang permanen (TX-002).
2. Fencing tidak berlaku di dalam transaksi bisnis (TX-001).
3. Tidak ada satu pun test yang membuktikan konkurensi, rollback parsial, atau crash recovery (TX-004). Constraint DB yang menjadi pertahanan terakhir juga tidak dapat diverifikasi (TX-003). Sesuai aturan proyek, verdict lulus membutuhkan bukti invarian di bawah eksekusi konkuren.

## 2. Ringkasan batas transaksi dan state ledger

**Alur nyata `ProvisioningService::provision()`:**

| # | Langkah                                                                                                                                                | Transaksi                                         |
| - | ------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------- |
| 1 | Baca ledger, `responseForExisting()`, atau `INSERT` ledger `pending` (`attempt_count=0`)                                                               | autocommit                                        |
| 2 | `claim()`: `UPDATE … WHERE status=:s AND attempt_count=:n`: `processing`, `attempt+1`, lease 10 mnt                                                   | autocommit, atomik                                |
| 3 | `committedProvisioning()`: cari tenant dengan `provisioning_idempotency_key`                                                                           | read                                              |
| 4 | Pre-check email (`runWithoutTenant`)                                                                                                                   | read                                              |
| 5 | **Transaksi bisnis** (maks. 2 percobaan): Tenant: Role Owner: Store: User owner (+employee_id): token: outbox: `provisionBaseline`: ActivityLog | 1 `DB::transaction`, tanpa `attempts`             |
| 6 | `finaliseLedger()`: `UPDATE … WHERE status='processing' AND attempt_count=:token`                                                                      | autocommit, **transaksi terpisah dari langkah 5** |
| 7 | `refreshReadiness()` (best-effort), `dispatch` outbox job                                                                                              | di luar transaksi                                 |

Ledger dan bisnis tidak atomik satu sama lain secara desain. Jembatannya adalah penanda `tenants.provisioning_idempotency_key`, yang ditulis di dalam transaksi bisnis.

**Transisi state ledger (nyata di kode):**

| Dari                     | Ke                  | Pelaku                               | Guard                                            |
| ------------------------ | ------------------- | ------------------------------------ | ------------------------------------------------ |
| ∅                        | PENDING             | `provision()`                        | PK/unique `idempotency_key` (belum diverifikasi) |
| PENDING                  | PROCESSING          | `claim()`                            | `attempt_count` sama; lease null/lewat           |
| FAILED (transient)       | PROCESSING          | `claim()`                            | `!isPermanentlyFailed()`                         |
| PROCESSING (lease lewat) | PROCESSING          | `claim()`                            | `attempt+1`                                      |
| PROCESSING               | COMPLETED / FAILED  | `finaliseLedger()`                   | status + `attempt_count` (fenced)                |
| PROCESSING (lease lewat) | COMPLETED / PENDING | `ReconcileProvisioningLedger`        | status + attempt + lease                         |
| COMPLETED                | (terminal)          | tidak ada jalur kode yang menurunkan | hanya di level aplikasi                          |

"Gagal permanen" diwakili oleh string `error_message === 'email_already_in_use'`, bukan kolom status tersendiri.

**Yang terkonfirmasi benar (analisis statis):**

- Klaim ledger atomik. Dua request bersamaan dengan key sama: hanya satu yang mendapat `rowCount=1`, yang lain membaca ulang lalu mendapat `pending`.
- `finaliseLedger()` tidak dapat menimpa COMPLETED atau klaim yang lebih baru, karena satu UPDATE bersyarat adalah titik fencing-nya.
- Klaim outbox atomik (`status` + `attempts` dalam UPDATE bersyarat).
- Konsumsi token onboarding atomik (`whereNull('consumed_at')`).
- Alokasi employee ID tidak memakai `MAX(id)+1`. Counter di-rollback bersama transaksi pemanggil, dan row lock membuat dua pemanggil tidak mungkin mendapat nomor sama.
- Email outbox ditulis di dalam transaksi bisnis. Pengiriman terjadi di luar transaksi, jadi kegagalan SMTP tidak membatalkan tenant.

## 3. Temuan

### TX-001: Fencing tidak ditegakkan di dalam transaksi bisnis

**Severity:** High. **Confidence:** Conditional (statis; bergantung pada unique index yang belum terlihat).\
&#x20;**File/method:** `ProvisioningService::provision()`: `provisionWithinTransaction()`, `finaliseLedger()`; `ReconcileProvisioningLedger::handle()`.

**Bukti:**

- Fencing token (`attempt_count`) hanya dipakai pada UPDATE ledger sesudah commit.
- Tidak ada `SELECT … FOR UPDATE` atau UPDATE ledger di dalam transaksi bisnis.
- Lease 10 menit tidak diperpanjang (tanpa heartbeat).
- Reconcile dan klaim ulang mengambil alih hanya berdasarkan waktu.
- `finaliseLedger()` tidak memeriksa jumlah baris terdampak, sehingga worker yang kalah fence tetap lanjut menjawab 201 dan men-dispatch outbox.

**Skenario pemicu:**

1. W1 mengklaim (attempt 1, lease T+10), lalu tertahan di tengah transaksi (lock wait panjang, proses di-suspend, partisi jaringan ke DB).
2. Pada T+10, W2 (retry klien atau reconcile) mengklaim attempt 2. `committedProvisioning()` kosong dan pre-check email lolos.
3. W1 pulih lalu commit. W2 gagal `1062` pada `tenants.provisioning_idempotency_key` atau `users.email`.
4. Catch W2: `User::where(email)` ada: `finaliseLedger(FAILED, email_already_in_use)` dengan token attempt 2: **ledger menjadi gagal permanen**. `finaliseLedger` W1 no-op.

**Dampak:**

- Tenant, owner, dan email hidup, tetapi ledger "failed permanen". Retry dengan key sama dijawab 409 "email sudah dipakai". Reconcile tidak memeriksa baris FAILED.
- Varian lain: Reconcile melepas klaim ke PENDING saat W1 masih hidup. Hasilnya tenant ada tetapi ledger PENDING tanpa penutup sampai klien retry.
- Bila unique index pada kedua kolom itu tidak ada, hasilnya **tenant duplikat** (naik ke Critical).

**Remediasi:**

- Jadikan fence bagian dari transaksi bisnis. Langkah pertama: `SELECT … FOR UPDATE` baris ledger dan verifikasi `status='processing' AND attempt_count=:token`.
- Langkah terakhir sebelum commit: UPDATE ledger: COMPLETED dengan hasil. Wajib `affected==1`, jika tidak lempar exception agar rollback.
- Cek `affected` pada setiap `finaliseLedger()`.
- Perpanjang lease (heartbeat) atau samakan lease dengan `innodb_lock_wait_timeout` + batas request.
- Kegagalan `email_already_in_use` hanya boleh ditulis bila bukan kolisi dengan hasil key yang sama (cek `provisioning_idempotency_key` terlebih dulu).

**Acceptance criteria:**

- Dengan W1 ditahan 11 menit mid-transaction, W2 mengklaim, lalu W1 dilepas: tepat 1 tenant, ledger COMPLETED dengan `result_tenant_id` = tenant itu, 0 baris FAILED permanen.
- Worker kalah fence tidak mengembalikan 201 dan tidak men-dispatch outbox.

**Regression test:**

- Test dua koneksi MySQL terpisah dengan barrier (`GET_LOCK` atau proses terpisah), lease dipaksa kedaluwarsa.
- Test `finaliseLedger` dengan token basi harus menyentuh predikat SQL (lihat TX-004).

**Terkait:** Claude 1 (skema unique index).

### TX-002: Baris outbox `processing` yang macet tidak pernah di-dispatch ulang

**Severity:** High. **Confidence:** Confirmed (statis); keberadaan schedule lain tidak dapat dikesampingkan karena `routes/console.php` NOT FOUND.\
&#x20;**File/method:** `ProcessEmailOutbox::handle()`, `ProcessProvisioningOutboxJob::claim()`.

**Bukti:**

- Scheduler hanya memilih `status = PENDING`.
- `claim()` memang mengizinkan pengambilalihan `PROCESSING` yang `next_attempt_at`-nya lewat, tetapi tidak ada yang men-dispatch job untuk baris itu.
- Retry framework (`$tries=3`, `retry_after` default) berlangsung di dalam lease 10 menit. `claim()` mengembalikan null, handle() selesai diam-diam, dan job habis.

**Skenario:**

1. Worker mengklaim baris (PROCESSING, `next_attempt_at=now+10m`).
2. Worker mati sebelum atau sesudah `Mail::raw`.
3. Baris tetap PROCESSING selamanya.

**Dampak:** Owner tidak pernah menerima tautan aktivasi, dan tidak ada jalur kirim ulang di kode yang disuplai. Hasilnya tenant "ready" yang tidak bisa dimasuki.

**Remediasi:**

- Scheduler juga memilih `PROCESSING` dengan `next_attempt_at <= now()`.
- Pisahkan kolom lease dari `next_attempt_at`.
- Beri status/alert untuk baris FAILED.
- Sediakan resend terotorisasi.

**Acceptance criteria:** Baris PROCESSING dengan lease lewat dikirim ulang dalam ≤ 1 siklus scheduler. Tidak ada baris PROCESSING dengan umur > lease + 1 siklus (query monitoring = 0).

**Regression test:**

- Set baris PROCESSING dengan lease lewat: jalankan `emails:process-outbox` + job: status SENT.
- Test dua worker yang berebut baris yang sama: tepat satu pengiriman (`Mail::fake`, lalu hitung).

### TX-003: Pertahanan constraint database tidak dapat diverifikasi

**Severity:** High (sampai dibuktikan). **Confidence:** Unable to Verify.

Seluruh jaminan "tidak ada duplikat" bersandar pada constraint yang tidak terlihat. Yang dimaksud:

- unique `provisioning_requests.idempotency_key`;
- unique `tenants.provisioning_idempotency_key`;
- unique `users.email` (global);
- unique `(tenant_id, user_id)` pada token;
- unique `users_employee_id_unique`;
- composite FK `result_user_id` ↔ `result_tenant_id` (test `test_ledger_result_user_must_belong_to_result_tenant` mengisyaratkan ada);
- unique `provisioning_nonces.nonce`;
- engine InnoDB untuk semua tabel;
- isolation level yang dikonfigurasi (default RR bila tidak diubah);
- index pendukung query klaim.

**Remediasi:** Lampirkan migration dan hasil `SHOW CREATE TABLE` / `information_schema` untuk tabel di atas, plus `SELECT @@transaction_isolation, @@innodb_lock_wait_timeout`.

**Acceptance criteria:** Setiap constraint di atas terbukti ada dan diuji pelanggarannya (percobaan INSERT duplikat: errno 1062).

### TX-004: Test tidak membuktikan konkurensi, rollback parsial, maupun recovery

**Severity:** High. **Confidence:** Confirmed (statis).\
&#x20;**File:** `tests/Feature/Domain1/ProvisioningDomainTest.php`.

| Test                                                                | Klasifikasi                 | Alasan                                                                                                                                                                                                 |
| ------------------------------------------------------------------- | --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `test_completed_cannot_be_overwritten_by_stale_worker_finalization` | **Misleading (vacuous)**    | Mengirim token `attempt_count - 1 = 0`. `finaliseLedger()` langsung `return` pada guard `fencingToken < 1`, jadi predikat UPDATE tidak pernah dijalankan.                                              |
| `test_stale_worker_cannot_complete_or_fail…`                        | Misleading                  | Menjalankan UPDATE mentah yang ditulis di dalam test itu sendiri, bukan kode service (tautologi).                                                                                                      |
| `test_parallel_requests_same_key_do_not_double_provision`           | Simulasi state berurutan    | Komentarnya mengakuinya: pra-insert ledger PROCESSING, bukan proses paralel.                                                                                                                           |
| `test_exception_during_provisioning_rolls_back_everything`          | Misleading                  | Yang diuji adalah pre-check email 409 sebelum transaksi. Tidak ada kegagalan di tengah penulisan parsial.                                                                                              |
| `test_unrelated_query_exception_propagates`                         | Misleading                  | Nama "propagates" tetapi asersi 500 (ditelan). Exception dilempar pada `Tenant::creating` (statement pertama), sehingga tidak ada penulisan parsial. Listener bocor bila asersi gagal sebelum cleanup. |
| `test_tenant_context_restored_after_exception`                      | Misleading                  | Payload valid, provisioning sukses; tidak ada jalur exception.                                                                                                                                         |
| `test_email_failure_does_not_cancel_provisioning`                   | Negative-path parsial       | Asersi `assertSame($outbox->body_text, decrypt($storedBody,false))` tautologi terhadap format enkripsi (lihat TX-011).                                                                                 |
| `test_reconcile_recovers_…`, `test_retry_after_completed_commit…`   | Simulasi recovery berurutan | Berguna untuk logika, bukan crash nyata.                                                                                                                                                               |
| `test_employee_ids_are_unique_and_sequence_is_monotonic…`           | Happy-path berurutan        | Tidak ada eksekusi paralel.                                                                                                                                                                            |

Faktor tambahan:

- `RefreshDatabase` membungkus semua dalam satu transaksi, sehingga transaksi bisnis menjadi savepoint. Kunci baris, deadlock, lock wait timeout, dan commit sungguhan tidak dapat teramati.
- `EmployeeIdGenerator` memakai `LAST_INSERT_ID()` (khusus MySQL). Tidak ada guard bahwa test berjalan di MySQL. Hanya `BaselineTest` yang punya guard nama DB.
- Tidak ada test untuk: dua worker outbox, crash setelah provider menerima, lease kedaluwarsa, kegagalan di tengah transaksi bisnis, deadlock, dan retry sesudah deadlock.
- Satu-satunya suite konkurensi di repo (`TransactionConcurrencyTest.php.disabled`) dinonaktifkan dan menyasar checkout, bukan provisioning.

**Remediasi:** Tambahkan suite non-transaksional (`DatabaseTruncation`, group `chaos`) dengan `Concurrency::driver('process')` dan barrier waktu, mengikuti pola file yang dinonaktifkan. Perbaiki test yang vacuous.

**Acceptance criteria:**

- Setiap test baru terbukti gagal bila fix-nya dicabut (mutation check manual).
- Suite berjalan hijau di MySQL/InnoDB nyata pada ≥ 20 iterasi tanpa flaky.

**Regression test yang wajib ada:**

1. 20 request paralel, key sama: 1 tenant, 1 ledger COMPLETED, 1 outbox row.
2. Key sama + fingerprint beda, paralel: tepat 1 pemenang, sisanya 409.
3. Fault injection di tengah transaksi bisnis (mis. setelah Store, sebelum Owner): 0 baris sisa di semua tabel.
4. Worker basi (token lama, status PROCESSING) memanggil `finaliseLedger`: baris tidak berubah (`affected=0`).
5. Dua worker outbox pada baris yang sama: tepat 1 `Mail::raw`.
6. 20 pembuatan user paralel pada tenant yang sama: 20 `employee_id` unik, 0 deadlock tak tertangani.

### TX-005: Rotasi token di setiap percobaan; `dispatch()` tidak membawa token

**Severity:** Medium. **Confidence:** Confirmed (statis).\
&#x20;**File:** `ProvisioningService::provision()`, `ProcessProvisioningOutboxJob::resolvePlaintextToken()`, `OwnerOnboardingToken::reissueFor()`.

**Bukti:**

- `provisionWithinTransaction()` mengembalikan `onboarding_token`, tetapi `provision()` hanya memanggil `ProcessProvisioningOutboxJob::dispatch($result['outbox_id'])`.
- `$onboardingToken` di job selalu null, jadi **setiap pengiriman, termasuk yang pertama, melewati jalur "pemulihan"** yang me-rotasi token.
- Plaintext hasil transaksi bisnis tidak pernah dipakai (kode mati). Komentarnya ("dititipkan di payload antrean") tidak sesuai perilaku nyata.

**Skenario:**

1. Percobaan 1: token dirotasi menjadi P1, `Mail::raw` timeout setelah server menerima pesan (hasil ambigu).
2. Job dijadwalkan ulang. Percobaan 2 merotasi lagi (P2) dan mencabut P1, yang mungkin sudah sampai ke inbox owner.
3. Bila percobaan 2–5 gagal, owner memegang tautan mati dan baris menjadi FAILED.

**Dampak:** Owner bisa terkunci dari aktivasi. Hanya satu jalur pengiriman yang konsisten, tetapi tidak ada jalur kirim ulang di kode yang disuplai.

**Remediasi:**

- Tentukan satu kebijakan eksplisit, misalnya rotasi hanya bila pengiriman sebelumnya terbukti gagal sebelum diserahkan ke provider, atau pertahankan token lama sampai ada pengiriman yang terkonfirmasi.
- Teruskan token (terenkripsi) ke dispatch pertama, atau hapus kode mati.
- Sediakan endpoint/command resend terotorisasi.

**Acceptance criteria:**

- Pengiriman sukses pertama tidak merotasi token (`send_attempts` tidak bertambah dua kali).
- Pada kegagalan ambigu, tautan terakhir yang terkirim tetap valid sampai kedaluwarsa, atau owner dapat meminta kirim ulang.

**Regression test:** Pengiriman sukses: satu rotasi paling banyak. Skenario timeout ambigu: status tautan terdokumentasi.

### TX-006: Risiko deadlock S ke X (Shared to Exclusive) pada alokasi `employee_id`

**Severity:** Medium. **Confidence:** Conditional (analisis perilaku InnoDB; tidak direproduksi).\
&#x20;**File/method:** `EmployeeIdGenerator::next()` / `ensureSequenceRow()` / `allocate()`.

**Bukti:** Setiap pembuatan user menjalankan `INSERT IGNORE` pada baris sequence yang sudah ada, lalu `UPDATE … LAST_INSERT_ID(last_value+1)` pada baris yang sama. Bila dipanggil di dalam transaksi (`UserService::store`, provisioning):

1. T1 dan T2 sama-sama `INSERT IGNORE` pada baris yang sudah ada. Pada duplikat, InnoDB menyetel shared lock (RR).
2. Keduanya lalu `UPDATE` yang membutuhkan X lock, sehingga masing-masing menunggu yang lain: **deadlock 1213**.

`DB::transaction` tanpa `attempts` tidak mengulang, jadi pembuatan user gagal dengan 500.

**Remediasi:** Lakukan `UPDATE` lebih dulu dan `INSERT` baris baru hanya bila `affected=0`. Atau pakai satu statement `INSERT … ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value+1)`. Beri `attempts: 3` pada transaksi pemanggil, atau tangkap 1213 secara eksplisit.

**Acceptance criteria:** 50 pembuatan user paralel pada satu tenant: 0 deadlock, 50 ID unik berurutan.

**Regression test:** Lihat TX-004 #6, dengan guard hasil `SHOW ENGINE INNODB STATUS` (opsional).

### TX-007: `LAST_INSERT_ID()` dibaca lewat `DB::selectOne`

**Severity:** Medium. **Confidence:** Conditional / Unable to Verify (`config/database.php` NOT FOUND).

`LAST_INSERT_ID(expr)` bersifat per-koneksi. `DB::selectOne()` memakai read PDO bila konfigurasi read/write split aktif tanpa `sticky`. Hasilnya bisa 0 atau salah, dan semua user menerima `…-0000`, yang menabrak `users_employee_id_unique`.

**Remediasi:** Baca nilai lewat write connection (`DB::connection()->selectOne(..., useReadPdo: false)`), atau gunakan nilai dari `UPDATE … RETURNING`-setara. Verifikasi konfigurasi koneksi.

**Acceptance criteria:** Dengan read/write split diaktifkan di test, 2 user berturut-turut menghasilkan ID berbeda dan bukan `0000`.

### TX-008: Generator `MAX(id)+1` lain berjalan di dalam transaksi provisioning

**Severity:** Medium. **Confidence:** Conditional (bergantung unique index `role_code` dan sejenisnya).\
&#x20;**File:** `Role::booted()` (`role_code`), juga `Category`, `Product`, `Table` (kode serupa).

**Bukti:** `static::withoutTenantScope()->latest('id')->first()` adalah consistent read tanpa lock lintas tenant, lalu kode dibangun dari `id+1`. Provisioning tenant paralel membangun kode identik (mis. `ROL-010`). Bila ada unique index, request kedua menunggu lock lalu mendapat `1062`.

**Dampak:** Kegagalan acak pada provisioning paralel. Loop bisnis hanya mengulang sekali, setelah itu `500 provisioning_failed` (lalu retry klien).

**Remediasi:** Pakai sequence atomik per tenant (pola `employee_id_sequences`), atau hasilkan kode setelah insert dari PK. Jangan memakai `MAX(id)`.

**Acceptance criteria:** 10 provisioning paralel: 10 tenant sukses, 0 `provisioning_failed`.

### TX-009: Cakupan reconcile dan ketergantungan scheduler

**Severity:** Medium. **Confidence:** Confirmed (statis) + Unable to Verify (jadwal).

- `ReconcileProvisioningLedger` hanya memindai `PROCESSING`. Baris PENDING atau FAILED yang tenant-nya sudah commit (lihat TX-001) tidak pernah direkonsiliasi, sehingga ada ledger yatim jika klien tidak retry.
- Jalur pemulihan (reconcile maupun `committedProvisioning()` di service) tidak men-dispatch outbox dan bergantung penuh pada `emails:process-outbox`.
- Baik reconcile maupun `emails:process-outbox` tidak terbukti dijadwalkan (`routes/console.php` NOT FOUND).
- Pemulihan di service membalas 200 `idempotent_replay`, bukan 201.

**Remediasi:** Jadwalkan kedua command. Perluas reconcile ke PENDING/FAILED yang punya penanda tenant. Tambahkan alert untuk baris yang menua.

**Acceptance criteria:** Tidak ada baris ledger non-terminal dengan umur > 2× lease (query monitoring = 0). Bukti jadwal di `routes/console.php`.

### TX-010: Klasifikasi exception dan retry MySQL

**Severity:** Medium. **Confidence:** Confirmed (statis).

- `isUniqueViolation()` menerima SQLSTATE `23000`/`23505`. Di MySQL `23000` mencakup juga FK (1451/1452) dan NOT NULL (1048), bukan hanya duplikat (1062). Hasilnya semuanya diperlakukan sebagai kolisi. Selain itu `VerifyProvisioningSignature` hanya memeriksa `getCode() === '23000'`, sehingga pelanggaran integritas non-duplikat dibaca sebagai replay nonce (409).
- Deadlock (1213, SQLSTATE 40001) dan lock wait timeout (1205, HY000) pada transaksi bisnis berujung ledger FAILED transient + 500 retryable. Ini aman tetapi tidak diulang otomatis.
- `claim()` dan `INSERT` ledger tidak menangkap 1205/1213 sehingga 500 mentah tanpa kontrak JSON.
- Request kedua dengan email yang sama menunggu pada unique index sampai `innodb_lock_wait_timeout` (default 50 d) atau commit pertama, mengikat worker HTTP.
- `claim()` yang null memberi 200 `pending` meskipun barisnya sudah COMPLETED atau konflik.

**Remediasi:** Periksa `errorInfo[1] === 1062` plus nama index. Tangani 1205/1213 dengan retry terbatas di service. Atur timeout lock yang lebih pendek untuk jalur provisioning.

**Acceptance criteria:** Test injeksi 1213 dan 1205: respons terstruktur dan ledger FAILED transient, bukan 500 mentah. Test FK violation tidak dilaporkan sebagai kolisi email.

### TX-011: Format enkripsi `body_text` outbox tidak konsisten

**Severity:** Low. **Confidence:** Conditional (perilaku default `encrypt()` Laravel).\
&#x20;**File:** `ProcessProvisioningOutboxJob` (`DB::table()->update(['body_text' => encrypt(...)])` dan `EmailOutbox::…->update([... encrypt(...)])`) versus cast `'encrypted'` pada `EmailOutbox`.

`encrypt($v)` men-serialize secara default, sedangkan cast model memakai `encryptString`/`decryptString`. Kolom yang ditulis lewat builder terbaca dari model sebagai string ber-wrapper `s:N:"…";`. Pada percobaan berikutnya body yang dikirim bisa memiliki wrapper itu, dan serialisasi bisa bertumpuk setiap rotasi. Tautan tetap valid. Asersi test `decrypt($storedBody,false)` tidak mendeteksinya.

**Remediasi:** Pakai `Crypt::encryptString()` atau jalur model yang sama. **Acceptance criteria:** Round-trip body setelah rotasi dan setelah sukses sama persis dengan teks asli.

### TX-012: Kerapuhan model state ledger

**Severity:** Low. **Confidence:** Confirmed (statis).

- Kegagalan permanen diwakili string `error_message`, bukan status.
- Hasil `finaliseLedger()` diabaikan (lihat TX-001).
- Keterminalan COMPLETED hanya di aplikasi. Tidak terlihat guard DB (trigger/CHECK), sedangkan test sendiri bisa meng-UPDATE mentah.
- Docblock `provisionWithinTransaction` menyebut penanda ditulis "saat finalisasi", tetapi kode menulisnya saat pembuatan tenant (ini yang benar secara desain).

**Remediasi:** Tambahkan status eksplisit `failed_permanent` dan guard DB untuk transisi dari COMPLETED.

### Observasi di luar inti scope (tidak dihitung dalam verdict)

- **OBS-01 (Conditional):** `TenantProvisioningService::systemUserEmail()` memberi tenant id 1 alamat `selforder.t1@system.local`, sedangkan `SystemUserResolver::selfOrder(1)` mencari `config('pos.system_users.self_order_email', 'selforder@system.local')`. Bila config tidak menyamakan keduanya, tenant 1 yang di-provision lewat jalur ini akan 500 pada self-order, padahal readiness melaporkan "ready". Konfirmasi `config/pos.php`.
- **OBS-02:** AUTO_INCREMENT InnoDB tidak di-rollback. Provisioning yang gagal menghabiskan ID, sehingga tenant "id 1" tidak dijamin sebagai tenant pertama yang sukses. Percabangan `tenant_id === 1` rapuh.
- **Milik Claude 2:** fingerprint memakai raw body, jadi retry dengan serialisasi JSON berbeda memberi 409 spurious. Respons `200 pending` untuk "sedang diproses" bisa disalahartikan klien sebagai sukses.

## 4. Matriks crash point dan recovery

| #   | Titik crash                                                      | State tertinggal                                              | Pemulihan                                                                                | Status                                   |
| --- | ---------------------------------------------------------------- | ------------------------------------------------------------- | ---------------------------------------------------------------------------------------- | ---------------------------------------- |
| C1  | Setelah INSERT ledger, sebelum claim                             | PENDING, attempt 0                                            | Retry klien meng-claim                                                                   | OK                                       |
| C2  | Setelah claim, di dalam transaksi bisnis                         | PROCESSING (lease 10 mnt); MySQL me-rollback transaksi        | Dalam lease: 200 pending. Sesudahnya: retry meng-claim atau reconcile melepas ke PENDING | OK (menunggu lease)                      |
| C3  | Worker stall > lease di tengah transaksi, lalu commit            | Dua pemegang klaim                                            | Hanya constraint DB                                                                      | **TX-001**                               |
| C4  | Commit sukses atau hasil commit ambigu, sebelum `finaliseLedger` | PROCESSING (atau FAILED transient bila exception saat commit) | `committedProvisioning()` di retry, atau reconcile (PROCESSING saja)                     | OK bila retry atau schedule ada (TX-009) |
| C5  | Setelah finalisasi, sebelum dispatch                             | Outbox PENDING (`next_attempt_at=now`)                        | `emails:process-outbox`                                                                  | Bergantung jadwal (TX-009)               |
| C6  | Worker outbox mati setelah klaim, sebelum kirim                  | Outbox PROCESSING                                             | **Tidak ada dispatcher**                                                                 | **TX-002**                               |
| C7  | Mati setelah rotasi token, sebelum kirim                         | PROCESSING; token baru tak pernah terkirim                    | Sama dengan C6                                                                           | TX-002/005                               |
| C8  | Provider menerima, mati sebelum SENT                             | PROCESSING                                                    | Tidak ada dispatcher. Bila di-dispatch: email ganda, tautan pertama mati                 | TX-002/005                               |
| C9  | Timeout ambigu (exception)                                       | PENDING + backoff                                             | Retry merotasi token (tautan sebelumnya mati)                                            | TX-005                                   |
| C10 | 5 percobaan habis                                                | FAILED                                                        | Tidak ada jalur kirim ulang                                                              | TX-005                                   |
| C11 | Mati di antara `reissueFor` dan UPDATE body                      | Transaksi rollback                                            | Token lama tetap                                                                         | OK                                       |
| C12 | Rollback transaksi bisnis                                        | Counter employee ID ikut rollback; AUTO_INCREMENT PK tidak    | Tidak ada duplikat                                                                       | OK (ID tenant/role bergaps)              |

## 5. Test yang dijalankan

**NOT RUN.** Tidak ada hasil test yang dapat diklaim. Semua klasifikasi di TX-004 berasal dari pembacaan kode test.

## 6. Blocker sebelum merge

1. **TX-002**: scheduler harus men-dispatch ulang outbox `PROCESSING` yang lease-nya lewat.
2. **TX-001**: fencing di dalam transaksi bisnis dan pengecekan `affected` pada `finaliseLedger`.
3. **TX-004**: perbaiki test yang vacuous. Tambahkan minimal test konkurensi (key sama paralel, dua worker outbox) dan test rollback parsial di MySQL nyata.
4. **TX-003**: sertakan migration dan bukti constraint.

## 7. Blocker sebelum produksi

- TX-006 dan TX-007 (alokasi `employee_id` di bawah beban paralel dan topologi koneksi nyata).
- TX-008 (provisioning paralel tanpa kolisi kode).
- TX-009 (jadwal reconcile dan outbox terbukti, ditambah alert baris menua).
- TX-005 (kebijakan rotasi token dan jalur resend).
- TX-010 (penanganan 1205/1213).
- Kebijakan retensi ledger/nonce/outbox (PRD OD-25/OD-29 masih OPEN).
- Runbook pemulihan manual untuk ledger yatim.

## 8. Ketidakpastian dan bukti yang dibutuhkan

| Hal                                                   | Bukti yang dibutuhkan                                                                                      |
| ----------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| Commit SHA dan status commit vs perubahan lokal       | `git rev-parse HEAD`, `git status --porcelain`, `git diff`                                                 |
| Semua constraint/engine/isolation                     | `database/migrations/` + `SHOW CREATE TABLE`, `SELECT @@transaction_isolation, @@innodb_lock_wait_timeout` |
| Jadwal reconcile dan outbox                           | `routes/console.php` + cron/supervisor produksi                                                            |
| `retry_after`, driver antrean, read/write split       | `config/queue.php`, `config/database.php`                                                                  |
| `pos.system_users.*` vs `pos.self_order_system_email` | `config/pos.php`                                                                                           |
| Perilaku deadlock InnoDB untuk TX-006                 | Reproduksi dua koneksi + `SHOW ENGINE INNODB STATUS`                                                       |
| Hasil test                                            | Eksekusi suite di MySQL/InnoDB nyata                                                                       |

## 9. Verdict akhir (transaction integrity & idempotency)

**FAIL (belum terbukti).**

- **Sudah benar:** klaim atomik pada ledger dan outbox, fencing pada finalisasi, marker commit untuk pemulihan, konsumsi token atomik, dan alokasi sequence di dalam transaksi pemanggil.
- **Yang menggagalkan:** satu cacat Confirmed (statis) yang menyebabkan email aktivasi hilang permanen (TX-002), fencing yang tidak mencakup transaksi bisnis (TX-001), dan ketiadaan bukti konkurensi, rollback parsial, serta constraint DB (TX-003, TX-004).
- **Jalur ke PASS WITH CONDITIONS:** tutup empat blocker merge di bagian 6, lalu serahkan bukti constraint, jadwal scheduler, dan hasil suite konkuren di MySQL nyata.

Domain 2 tidak dimulai dan tidak ada perbaikan yang diterapkan.



# Laporan Audit Domain 1: Onboarding & Operational Readiness, Apeiron System

## 1. Verdict Eksekutif

| Aspek                                                  | Verdict                  |
| ------------------------------------------------------ | ------------------------ |
| Alur onboarding owner (jalur bahagia, analisis statis) | **PASS WITH CONDITIONS** |
| Operational readiness                                  | **FAIL**                 |
| **Keseluruhan**                                        | **FAIL**                 |

Alasan FAIL:

- Ada tiga temuan High yang terkonfirmasi di kode: OR-001, OR-002 dan OR-004.
- Tidak ada satu pun test yang dijalankan.
- Migrasi dan bootstrap tidak tersedia, sehingga syarat PASS (bukti bahwa onboarding berhasil dan tenant punya konfigurasi minimum) belum bisa dipenuhi.

Jalur bahagia secara statis koheren: provisioning, token, outbox, email, magic link, password, lalu login. Namun tenant hasil provisioning belum terbukti siap dipakai, dan pemulihan saat ada kegagalan punya celah.

## 2. Snapshot yang Diaudit

- **Sumber:** sinkronisasi GitHub terlampir, `IlhamSpryd/point-of-Sales`, branch `main`, filter `/app/ /tests/ /config/ /routes/`, ditambah PRD "Apeiron Systems — Final Master PRD.docx".
- **Commit SHA:** tidak tersedia (workspace bukan repo git, `fatal: not a git repository`).
- **Committed atau working tree:** **Unable to Verify.** Saya tidak boleh menganggap snapshot ini sama dengan HEAD GitHub.
- **Nomor baris:** tidak tersedia. Referensi memakai format `file::method`.
- **Tidak ditemukan (NOT FOUND):**&#x20;
  - `bootstrap/app.php`
  - `database/migrations/`
  - views
  - `composer.json`
  - `phpunit.xml`
  - UserFactory
  - `.env.example`
  - `docs/architecture/PROVISIONING.md`
- **Lingkungan:** ada PHP dan Composer, tetapi tidak ada MySQL/mysqld dan tidak ada file proyek di disk.
- **Tindakan yang dilakukan:** tidak ada kode yang diubah, tidak ada migrasi yang dijalankan, tidak ada test yang dijalankan.

## 3. Alur Onboarding End-to-End

| # | Tahap              | Komponen aktual                                                                                                                                                                                                                                                           | Status bukti                              |
| - | ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------- |
| 1 | S2S masuk          | `POST /v1/provisioning/registrations` (`routes/api.php`), middleware `throttle:10,1` dan `verify.provisioning` (HMAC, jendela timestamp, nonce)                                                                                                                           | Kode terbaca                              |
| 2 | Ledger idempotensi | `ProvisioningService::provision()` membuat `ProvisioningRequest`, claim dengan fencing `attempt_count` dan lease, cek konflik email                                                                                                                                       | Kode terbaca                              |
| 3 | Pembuatan tenant   | `runWithoutTenant` + `DB::transaction` membuat Tenant, Role Owner (`['*']`), Store, User Owner (`password=null`, `email_verified_at=null`), `OwnerOnboardingToken` (hash SHA-256), `EmailOutbox`, `provisionBaseline` (role default, system user, setting, `ActivityLog`) | Kode terbaca                              |
| 4 | Dispatch           | `ProcessProvisioningOutboxJob::dispatch($outbox_id)`. Token plaintext tidak ikut di payload dan di-reissue saat job berjalan                                                                                                                                              | Kode terbaca                              |
| 5 | Email              | Job claim atomik, `resolvePlaintextToken()` memanggil `reissueFor`, kirim lewat mailer; sukses menulis body yang diredaksi                                                                                                                                                | Kode terbaca, runtime tidak terverifikasi |
| 6 | Magic link         | `GET/POST onboarding.set-password` (`throttle:6,1`) menuju `OwnerOnboardingController::show/store`                                                                                                                                                                        | Cocok dengan URL yang dibangun            |
| 7 | Redeem             | `findValidToken` lalu `DB::transaction`: `consume()` (UPDATE atomik `whereNull consumed_at`), lalu set `password_hash` (Hash::make) dan `email_verified_at`                                                                                                               | Kode terbaca                              |
| 8 | Login              | `LoginRequest` (rate limit + `is_active`), `User::getAuthPassword()` = `password_hash ?? password`                                                                                                                                                                        | Konsisten                                 |
| 9 | Akses POS          | Group `['auth','tenant.context','enforce.email.verification']`, lalu `/home`: Owner ke dashboard. `SetTenantContext` menetapkan TenantContext; `StoreResolver` memberi Owner semua store                                                                                 | Kode terbaca                              |

Token: `Str::random(48)`, hash SHA-256 di DB, sekali pakai, TTL 48 jam, terikat tenant dan user.

## 4. Temuan (urut severity)

### OR-001: Nama email system user channel-order tidak konsisten (High, Confirmed, Code)

- **Bukti:** `TenantProvisioningService::systemUserEmail()` membuat `channel-order.t{id}@system.local`, sedangkan `SystemUserResolver` untuk tenant ≠ 1 mencari `channel.t{id}@system.local` dan melakukan `abort(500)` bila tidak ada.
- **Pemicu:** tenant baru (id ≠ 1) memproses order channel (webhook/channel order). Jalur self-order tampaknya konsisten (`selforder.t{id}`).
- **Dampak:**&#x20;
  - Order channel gagal dengan HTTP 500.
  - `evaluateReadiness` memakai `systemUserEmail()` yang sama, sehingga status tenant tetap "ready" padahal resolver gagal.
  - Test fixture `WithDefaultTenant` memakai command lama `ProvisionSystemUsers` (penamaan resolver), sehingga bug ini tidak tertangkap.
- **Remediasi:** satu sumber kebenaran untuk penamaan, dipakai bersama oleh provisioning, resolver dan readiness check.
- **Acceptance:** tenant hasil provisioning S2S (tanpa command lama) berhasil di-resolve oleh `SystemUserResolver` untuk kedua jenis system user; readiness gagal jika resolver gagal.
- **Regression test:** provision tenant id ≠ 1 lewat S2S, lalu panggil resolver untuk self-order dan channel; assert tidak ada abort dan email sama dengan yang dicek readiness.
- **Terkait:** irisan dengan Claude 1 (isolasi tenant).

### OR-002: Tidak ada jalur resend/recovery aktivasi owner (High, Confirmed, Code)

- **Bukti:**&#x20;
  - `OwnerOnboardingToken::reissueFor` hanya menerima token yang belum dikonsumsi dan belum kedaluwarsa.
  - `routes/auth.php` tidak di-require dari `routes/web.php`, jadi route forgot/reset password tidak terdaftar. Ini Conditional terhadap isi `bootstrap/app.php` yang tidak ditemukan.
  - Outbox berhenti setelah 5 percobaan.
- **Pemicu:** link kedaluwarsa (48 jam), email gagal terus, atau email hilang.
- **Dampak:** owner tidak bisa aktivasi dan tidak bisa reset. Tenant terjebak tanpa jalur pemulihan selain intervensi manual di DB.
- **Remediasi:** sediakan mekanisme pembuatan token baru (endpoint/command operator atau flow lupa-password yang aman) yang me-rotate token lama.
- **Acceptance:** owner dengan token kedaluwarsa atau outbox gagal dapat menerima link baru tanpa membuat tenant atau user duplikat.
- **Regression test:** token expired lalu resend lalu aktivasi lalu login; token lama tidak bisa dipakai.
- **Terkait:** produk, OD-29 (onboarding belum didefinisikan di PRD).

### OR-004: Baris outbox `processing` tidak pernah di-dispatch ulang (High, Confirmed, Operations)

- **Bukti:** `ProcessProvisioningOutboxJob::claim()` menerima `processing` dengan lease kedaluwarsa, tetapi `ProcessEmailOutbox` hanya memilih `status=pending` yang jatuh tempo.
- **Pemicu:** worker mati atau crash setelah claim dan sebelum update status.
- **Dampak:** email aktivasi tidak pernah terkirim, baris tertahan di `processing` permanen, owner tidak bisa aktivasi.
- **Remediasi:** scheduler fallback juga memilih `processing` dengan lease kedaluwarsa.
- **Acceptance:** baris `processing` dengan lease kedaluwarsa diambil ulang dan terkirim dalam satu siklus scheduler.
- **Regression test:** simulasi crash setelah claim, lalu jalankan `emails:process-outbox`, lalu assert terkirim satu kali.

### OR-003: Reset password menulis kolom yang salah (High, Confirmed, Code, latent)

- **Bukti:** `Auth\NewPasswordController` memakai `forceFill(['password' => ...])`, padahal autentikasi memakai `password_hash ?? password`. `Auth\PasswordController::update` benar menulis `password_hash`.
- **Pemicu:** route reset diaktifkan setelah user punya `password_hash`.
- **Dampak:** reset tampak sukses tetapi password lama tetap berlaku.
- **Catatan:** saat ini laten karena route tidak terdaftar. Ini menjadi relevan begitu OR-002 diperbaiki lewat flow reset.
- **Remediasi:** tulis `password_hash` (atau jadikan `getAuthPasswordName` konsisten).
- **Acceptance:** setelah reset, login dengan password baru berhasil dan password lama gagal.
- **Regression test:** aktivasi, reset, login dengan password baru.

### OR-008: Token meja deterministik dan tanpa tenant (High, Confirmed, Security)

- **Bukti:**&#x20;
  - `Table` memakai `md5('apeiron-pos-qr-'.strtolower(trim($table_name)))` sebagai `secure_token`.
  - `ResolveTableFromToken` mencari dengan `Table::withoutGlobalScopes()`.
- **Pemicu:** dua tenant punya nama meja sama (mis. "Meja 1"), atau pihak luar menebak token.
- **Dampak:** token bisa ditebak dan bertabrakan lintas tenant, sehingga self-order bisa terhubung ke tenant lain. Ini memengaruhi kesiapan fitur self-order tenant baru.
- **Remediasi:** token acak berentropi tinggi, unik global atau terikat tenant.
- **Acceptance:** dua tenant dengan nama meja sama punya token berbeda dan tidak bisa ditebak dari nama meja.
- **Terkait:** milik Claude 1 (isolasi tenant); dicatat di sini karena memengaruhi readiness self-order.

### OR-005: `encrypt()` pada query builder tidak sama dengan cast `encrypted` (Medium, Conditional, Code)

- **Bukti:**&#x20;
  - `EmailOutbox` memakai cast `'body_text' => 'encrypted'`, yang memakai `encryptString`.
  - Job menulis `DB::table('email_outbox')->update(['body_text' => encrypt(...)])`, yang memakai `encrypt()` dengan serialisasi.
- **Dampak:** pembacaan lewat model bisa menghasilkan string terserialisasi, bukan teks asli. Perlu verifikasi runtime.
- **Remediasi:** gunakan `encryptString` atau tulis lewat model.
- **Regression test:** roundtrip tulis lewat job lalu baca lewat model, assert isi sama dengan body asli.

### OR-006: Host magic link diambil dari request (Medium, Conditional, Security)

- **Bukti:** `route('onboarding.set-password', ...)` dibangun di dalam request S2S.
- **Dampak:** host link tergantung host request S2S/APP_URL dan trusted hosts; bisa menghasilkan link ke host internal atau tidak valid. Perlu verifikasi konfigurasi `.env` dan trusted proxies/hosts.
- **Remediasi:** paksa root URL dari konfigurasi (`APP_URL`) saat membangun link.

### OR-007: Baseline settings dan readiness tidak dipakai; teks dan pajak hardcoded (Medium, Confirmed, Code)

- **Bukti:**&#x20;
  - `readiness_status` disimpan tetapi tidak pernah di-enforce.
  - `ReceiptPrinterService` memakai teks "Apeiron System / Jl. Mawar No. 123, Jakarta".
  - `config/pos.php` memakai `tax_rate 0.11`, sementara baseline setting `tax_enabled='false'`.
- **Dampak:**&#x20;
  - Struk tenant baru memuat identitas dan alamat yang salah.
  - Konfigurasi pajak tidak jelas (bertentangan dengan A-14/AS-015, OD-12).
  - Tenant "not ready" tetap bisa dipakai.
- **Remediasi:** ambil nama dan alamat dari Setting/Store; tentukan sumber pajak tunggal.

### OR-009: Tabrakan `role_code` (Medium, Confirmed, Database)

- **Bukti:** `Role` membuat `'ROL-'.str_pad($latest->id+1, 3, '0')` tanpa tenant.
- **Dampak:** kode non-unik atau bergantung pada urutan id global, dan berpotensi bentrok jika ada unique index. Index tidak bisa diverifikasi karena migrasi NOT FOUND.
- **Remediasi:** kode terikat tenant dan unik per tenant.

### OR-010: Zona waktu dan cutoff bisnis tidak ada (Medium, Confirmed, Product Decision)

- **Bukti:** `config/app.php` memakai timezone `'UTC'` hardcoded; tenant belum menyimpan timezone.
- **Dampak:** laporan harian dan business date (FR-RPT-003) akan salah untuk tenant WIB.
- **Remediasi:** simpan timezone per store atau tenant dan gunakan dalam pelaporan.

### OR-011: Default operasional dan prasyarat (Medium, Conditional, Operations)

- **Bukti:**&#x20;
  - `QUEUE_CONNECTION=database` secara default, sehingga membutuhkan tabel `jobs` dan `failed_jobs` (migrasi NOT FOUND) serta worker yang berjalan.
  - Job punya `$tries=3` tetapi `handle()` menelan exception.
  - Tidak ada method `failed()`.
  - Konstruktor job memiliki parameter `$onboardingToken` yang tidak terpakai.
  - `correlation_id` tidak dimanfaatkan untuk diagnostik.
- **Dampak:** retry bawaan Laravel tidak pernah aktif, kegagalan senyap, dan tidak ada alarm.
- **Remediasi:** tentukan satu mekanisme retry (manual backoff atau `tries`), tambahkan `failed()` dan logging terstruktur.

### OR-012: Failover mailer `smtp` ke `log` dapat membocorkan token (Medium, Conditional, Security)

- **Bukti:** `config/mail.php` memakai failover `['smtp','log']`. Body email membawa token plaintext saat dikirim.
- **Pemicu:** SMTP gagal sehingga mail di-log.
- **Dampak:** magic link aktif tertulis di log aplikasi. Guard produksi di `AppServiceProvider` hanya menolak `mail.default==='log'`, bukan failover.
- **Remediasi:** hapus `log` dari failover di produksi atau redaksi body.

### OR-013: Owner dapat menghapus diri sendiri; email soft-deleted (Low–Medium, Confirmed, Code)

- **Bukti:** `ProfileController::destroy` tidak punya guard last-owner; `User` memakai SoftDeletes.
- **Dampak:** tenant bisa kehilangan owner satu-satunya, dan email soft-deleted bisa memblokir provisioning ulang (tergantung unique index, tidak terverifikasi).

### Temuan Low (ringkas)

- Token ikut terbakar bila validasi password gagal setelah `consume()`; status ditulis dan di-commit.
- Retensi dan pembersihan token dan outbox tidak ada.
- Parameter `$onboardingToken` yang tidak terpakai di job.
- Route `verification.verify` berada di luar `auth`.
- Throttle key `onboarding` dipakai bersama GET dan POST.
- `correlation_id` tidak dimanfaatkan.
- Kebutuhan PHP ≥ 8.4 (`Pdo\Mysql`, `#[Signature]`) harus cocok dengan server produksi.

## 5. Matriks Kesiapan Tenant

| Komponen                              | Pembuatan                         | Mode kegagalan bila hilang                          | Catatan                   |
| ------------------------------------- | --------------------------------- | --------------------------------------------------- | ------------------------- |
| Tenant, Store                         | Otomatis (transaksi provisioning) | Tidak ada tenant                                    | OK                        |
| Role Owner (`['*']`) + 7 role default | Otomatis (`RoleService`)          | Owner tanpa hak akses                               | OK secara statis          |
| User Owner                            | Otomatis, password null           | Tidak bisa login sebelum aktivasi                   | Bergantung OR-002         |
| Token + email aktivasi                | Otomatis, via queue               | Owner tidak dapat link                              | OR-004, OR-012            |
| System user self-order                | Otomatis                          | Self-order 500                                      | Penamaan tampak konsisten |
| System user channel-order             | Otomatis tetapi nama salah        | Order channel 500                                   | **OR-001**                |
| Setting baseline                      | Otomatis (3 key)                  | —                                                   | Tidak dipakai (OR-007)    |
| Pajak                                 | Config global 0.11                | Pajak salah                                         | OR-007, OD-12             |
| Employee ID                           | Lazy, atomik                      | —                                                   | OK                        |
| Token meja QR                         | Deterministik                     | Lintas tenant                                       | OR-008                    |
| Midtrans, webhook tenant default      | Config global, manual             | Pembayaran/webhook tidak per tenant                 | Keputusan produk          |
| Struk                                 | Hardcoded                         | Identitas salah                                     | OR-007                    |
| Timezone                              | Tidak ada                         | Laporan salah                                       | OR-010                    |
| Manajemen store dan `user_stores`     | Tidak ditemukan UI                | Non-owner tanpa store (kecuali fallback satu store) | Keputusan produk          |
| `readiness_status`                    | Dihitung, tidak ditegakkan        | Tenant belum siap tetap bisa dipakai                | OR-007                    |
| Worker queue dan scheduler            | Infrastruktur                     | Email dan rekonsiliasi tidak jalan                  | OR-011                    |

## 6. Prasyarat Produksi dan Asumsi yang Belum Terverifikasi

Dari kode (terbukti):

- `routes/console.php` menjadwalkan `emails:process-outbox` (tiap menit), `provisioning:prune-nonces` (10 menit) dan `provisioning:reconcile` (5 menit), selain backup, restock, `orders:expire-stale-cash` dan `orders:reconcile-midtrans`. Semua command yang dirujuk ada di snapshot.
- Guard produksi di `AppServiceProvider`: boot gagal bila `mail.default==='log'` atau server key Midtrans kosong.
- `ReconcileProvisioningLedger` hanya menangani baris ledger `processing` dengan lease kedaluwarsa, lalu menyelesaikannya dari fakta yang sudah ter-commit atau mengembalikannya ke `pending`.

Tidak bisa diverifikasi dari repositori (asumsi):

- Cron `schedule:run` aktif dan worker queue berjalan (supervisor).
- Tabel `jobs`, `failed_jobs`, `email_outbox`, `owner_onboarding_tokens` dan ledger ada dan memakai InnoDB, termasuk FK dan index (migrasi NOT FOUND).
- Urutan migrasi, instalasi fresh dan upgrade, serta migrasi yang dihapus atau di-rename.
- `bootstrap/app.php`: middleware alias (`tenant.context`, `enforce.email.verification`, `verify.provisioning`), trusted hosts/proxies, dan apakah `routes/auth.php` dimuat di tempat lain.
- Konfigurasi `.env`: `APP_URL`, mailer produksi, secret provisioning, versi PHP ≥ 8.4, versi MySQL.
- Keadaan database produksi (tidak ada bukti, tidak diklaim).

## 7. Test yang Dijalankan

**NOT RUN.** Tidak ada MySQL, file proyek tidak ada di disk, dan migrasi/`phpunit.xml`/factory tidak tersedia. Saya tidak menyatakan satu test pun lulus.

Klasifikasi statis (berdasarkan pembacaan, bukan eksekusi):

- **`tests/Feature/Domain1/ProvisioningDomainTest.php` dan test terkait:** tidak dapat dibuktikan lulus. Fixture `WithDefaultTenant` memakai command lama `ProvisionSystemUsers`, sehingga **berpotensi menyesatkan** terhadap OR-001.
- **Tidak teridentifikasi dalam pembacaan saya** (perlu dikonfirmasi): test untuk token cross-tenant dan reuse, aktivasi lalu login nyata, retry email setelah gagal, crash recovery outbox `processing` (OR-004), penghapusan token sensitif atau enkripsi roundtrip (OR-005), migrasi fresh/upgrade, dan registrasi command/scheduler. Statusnya **incomplete** sampai ada bukti.

## 8. Blocker Sebelum Merge

1. OR-001: satukan penamaan system user dan buat readiness benar-benar menguji resolver.
2. OR-004: scheduler fallback harus mengambil outbox `processing` yang lease-nya kedaluwarsa.
3. OR-003: perbaiki kolom reset atau pastikan route reset tetap tidak terdaftar, dengan test.
4. OR-008: koordinasi dengan Claude 1 untuk token meja.
5. OR-012: tutup kebocoran token lewat failover `log`.
6. Sediakan commit SHA, `database/migrations/`, `bootstrap/app.php` dan hasil test yang dijalankan di MySQL/InnoDB. Tanpa ini PASS tidak bisa diberikan.

## 9. Blocker Sebelum Produksi

1. OR-002: jalur resend atau recovery aktivasi owner.
2. OR-005 dan OR-006: verifikasi runtime enkripsi outbox dan host magic link.
3. OR-007 dan OR-010: identitas struk, pajak dan timezone per tenant.
4. OR-011: worker queue, scheduler, `failed()` handler dan alarm kegagalan outbox.
5. Verifikasi infrastruktur (bagian 6): mailer produksi, `APP_URL`, tabel dan FK/index InnoDB, urutan migrasi fresh dan upgrade.
6. Keputusan produk di bawah ini terselesaikan, atau secara eksplisit ditunda.

## 10. Keputusan Produk dan Bukti yang Masih Kurang

Keputusan produk (bukan temuan kode):

- Definisi onboarding tenant (OD-29, "Not defined").
- Metode login non-kasir dan Owner (OD-09).
- Default pajak dan sumber tarif (OD-12).
- Midtrans per tenant atau global; tenant/store default webhook; kunci QZ.
- Status langganan/plan tenant.
- UI manajemen store dan `user_stores`.
- Apakah tenant "not ready" boleh dipakai (enforcement `readiness_status`).

Bukti tambahan yang diperlukan:

- Commit SHA dan status commit vs working tree.
- `database/migrations/` lengkap, `bootstrap/app.php`, `composer.json`, `phpunit.xml`, factory, views, `.env.example`.
- Hasil eksekusi test di MySQL/InnoDB (instalasi fresh dan upgrade dari skema yang sudah ada).
- `SHOW CREATE TABLE` atau skema aktual untuk tabel onboarding dan ledger (FK, index, engine).
- Konfigurasi runtime: worker, cron, mailer, trusted hosts.

Domain 2 tidak dimulai. Saya bisa menyimpan laporan ini ke Project sebagai dokumen jika Anda mau, atau menjalankan ulang audit begitu snapshot dengan SHA dan migrasi tersedia.