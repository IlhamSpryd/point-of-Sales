# DOMAIN 09 — PERFORMANCE, SCALABILITY & OBSERVABILITY AUDIT
## Apeiron Systems — Multi-Tenant Enterprise SaaS ERP Platform

- **Auditor:** A0 — Principal Enterprise Architect & Performance Auditor
- **Audit Target:** Query Efficiency, Index Strategy, N+1 Risks, Cache Architecture, Worker Throughput, Noisy-Neighbor Tenant Isolation, Structured Logging, Correlation IDs, and Observability
- **Scope:** Database Indexing, Redis Cache, Queue Pipelines, Logging Channels, and Telemetry Readiness
- **Audit Date:** 1 November 2026
- **Status:** **NEEDS REMEDIATION**
- **Confidence:** **HIGH** (Empirically verified via database index schema, Redis configuration, and logging pipeline analysis)

---

## 1. EXECUTIVE SUMMARY & DOMAIN VERDICT

Apeiron Systems telah membangun optimasi performa awal yang terarah:
- **Strategi Index Komposit Multi-Tenant:** Sebagian besar tabel transaksi memiliki indeks gabungan komposit `(tenant_id, store_id)`, `(tenant_id, created_at)`, dan `(tenant_id, status)`, memastikan query tersaring langsung pada partisi logis tenant.
- **Caching Terdistribusi dengan Tenant Scoping:** `DashboardService` mengimplementasikan caching Redis selama 90 detik dengan kunci berscope: `"pos:dashboard:{$tenantId}:{$cacheScope}:metrics_v3"` dan proteksi *fail-closed* yang melarang pembacaan cache jika tenant context kosong.
- **Asynchronous Exporting:** Laporan berukuran besar didelegasikan ke background worker menggunakan antrean melalui `AsyncExportButton.php`.
- **Health Check Endpoint:** Rute `/up` aktif di `bootstrap/app.php` untuk integrasi load balancer health checking.

Namun, ditinjau dari kacamata SaaS Enterprise multi-tenant berskala ribuan outlet:
1. **Ketiadaan Correlation ID & Structured JSON Logging (P2):** Logging aplikasi masih menggunakan format teks mentah monolitis (`storage/logs/laravel.log`). Tidak ada middleware yang menyuntikkan `X-Correlation-ID` atau mengikat metadata `tenant_id` secara otomatis ke dalam konteks logger.
2. **Ketiadaan Proteksi Noisy-Neighbor (P2):** Seluruh tenant berbagi satu database pool MariaDB dan Redis tanpa alokasi kuota komputasi atau rate limiting per-tenant, sehingga satu tenant dengan lonjakan transaksi ekstrem dapat memperlambat tenant lain.
3. **Ketiadaan Telemetry APM & Metrik Terdistribusi (P2):** Tidak ada instrumentasi OpenTelemetry, Prometheus exporter, atau integrasi monitoring performa aplikasi (APM) untuk mendeteksi slow queries secara proaktif.

**Domain 9 Verdict:** **`NEEDS REMEDIATION`**

---

## 2. DATABASE PERFORMANCE & QUERY EFFICIENCY

### 2.1 Index Strategy & Query Optimization
- Tabel `orders` memiliki indeks komposit:
  - `(tenant_id, store_id, order_status, created_at)`
  - `(tenant_id, idempotency_key)`
- Tabel `stock_movements` memiliki indeks:
  - `(tenant_id, store_id, product_id, created_at)`
- Tabel `journal_entries` memiliki indeks:
  - `(tenant_id, accounting_period_id, posting_date)`

### 2.2 N+1 Query Risks in Livewire Views
- Komponen Livewire yang menampilkan tabel besar (seperti `ShiftManager.php` dan `OrderHistory.php`) membutuhkan kehati-hatian dalam eager-loading. Saat merender riwayat pembayaran kasir, query relasi `$order->payments` yang dipanggil berulang di dalam loop Blade dapat memicu query amplification jika tidak dimuat dengan `with('payments')`.

---

## 3. OBSERVABILITY, LOGGING & DISTRIBUTED TRACING

### 3.1 Status Logging Saat Ini
- Driver: `single` dan `daily` pada `config/logging.php`.
- Format: Text log konvensional Monolog.
- Konteks: Sebagian besar log memanggil `Log::error('Pesanan gagal: '.$e->getMessage(), ['order_id' => ...])` secara manual tanpa standardisasi skema JSON.

### 3.2 Analisis Kebutuhan Enterprise Observability
- **Trace Context:** Tanpa Correlation ID, saat terjadi kegagalan transaksi kasir yang melibatkan Livewire -> Service -> Outbox -> Queue Job -> GL Posting, auditor atau devops tidak dapat merekonstruksi jejak perjalanan request (*end-to-end trace*) lintas worker.
- **Tenant Tagging:** Log harus secara otomatis diperkaya (*enriched*) dengan:
  `{"correlation_id": "uuid", "tenant_id": 2, "store_id": 5, "user_id": 14, "module": "POS"}`.

---

## 4. DOMAIN 9 FINDINGS REGISTER

### FINDING-DOM09-01: Absence of Request Correlation IDs and Structured JSON Logging
- **Domain:** Domain 9 — Observability & Operational Diagnostics
- **Severity:** **P2** (Material Observability Deficit)
- **Confidence:** **HIGH** (Empirically verified in `config/logging.php` and log calls)
- **File Path:** [`config/logging.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/config/logging.php)
- **Evidence:** Saluran log `single` dan `daily` tidak menggunakan Monolog `JsonFormatter`. Tidak ada middleware global yang menghasilkan dan meneruskan header `X-Request-ID` / `X-Correlation-ID`.
- **Actual Behavior:** Log tersimpan dalam format teks baris tunggal. Menelusuri error yang terjadi pada satu tenant tertentu di tengah jutaan baris log dari tenant lain memerlukan parsing teks yang lambat dan rentan salah.
- **Expected Behavior:** Platform enterprise SaaS menggunakan Structured JSON Logging terpusat dengan injeksi context otomatis (`tenant_id`, `store_id`, `correlation_id`, `duration_ms`) pada setiap entri log untuk integrasi dengan log aggregator (Grafana Loki, Elasticsearch, atau Datadog).
- **Root Cause:** Konfigurasi logging bawaan Laravel yang belum di-upgrade ke format JSON cloud-native.
- **Business Impact:** Waktu investigasi insiden (*Mean Time to Resolution - MTTR*) membengkak saat outlet melaporkan gangguan transaksi di produksi.
- **Recommendation:**
  1. Pasang middleware global `AssignCorrelationId` yang menyuntikkan ID unik ke request dan konteks logger: `Log::withContext(['correlation_id' => $id, 'tenant_id' => $tenantId])`.
  2. Tambahkan channel `json` di `config/logging.php` dengan `Monolog\Formatter\JsonFormatter`.

---

### FINDING-DOM09-02: Absence of Per-Tenant Workload Throttling (Noisy-Neighbor Vulnerability)
- **Domain:** Domain 9 — Scalability & Tenant Workload Isolation
- **Severity:** **P2** (Scalability & SLA Risk)
- **Confidence:** **HIGH** (Empirically verified in `app/Providers/AppServiceProvider.php` lines 112–136)
- **File Path:** [`app/Providers/AppServiceProvider.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Providers/AppServiceProvider.php#L112-L136)
- **Evidence:** Definisi `RateLimiter` hanya membatasi request per IP (`by('ip:'.$r->ip())`) dan per Session (`by('sess:'.$sid($r))`). Tidak ada pembatasan request per Tenant (`by('tenant:'.$tenantId)`).
- **Actual Behavior:** Jika Tenant A menjalankan skrip otomatis atau integrasi eksternal yang mengirim ribuan request per detik dari puluhan IP berbeda, beban database MariaDB dan Redis akan melonjak drastis, menurunkan performa dan meningkatkan latensi bagi Tenant B dan Tenant C (*noisy neighbor effect*).
- **Expected Behavior:** Multi-tenant SaaS enterprise harus memiliki tier-based rate limiting per tenant untuk melindungi stabilitas platform bersama.
- **Root Cause:** Kebijakan rate limiting masih berfokus pada pencegahan brute-force kasir tunggal, bukan tata kelola kuota SaaS multi-tenant.
- **Business Impact:** Risiko pelanggaran SLA performa bagi tenant besar akibat aktivitas berlebih dari tenant lain pada infrastruktur shared database.
- **Recommendation:** Tambahkan pembatas laju global berbasis tenant ID: `Limit::perMinute(1000)->by('tenant:'.$tenantId)` pada middleware API dan Livewire.

---

## 5. CAPABILITY GAP MATRIX (SCALABILITY & OBSERVABILITY)

| Performance / Observability Metric | Status Aktual Apeiron | Benchmark Enterprise (SAP BTP / NetSuite SuiteCloud) | Gap Analysis & Kebutuhan |
|---|---|---|---|
| **Database Indexing** | **Implemented & Verified** | Highly Tuned Composite Indexes per Tenant & Timestamp | Indeks komposit multi-tenant sangat lengkap dan presisi. |
| **Distributed Caching (Redis)** | **Implemented & Verified** | Multi-Tier Cache with Tenant Partitioning & Invalidation | Diterapkan pada Dashboard dan Menu Catalog. |
| **Structured JSON Logging** | **Not Implemented** | Native JSON with Trace/Span Correlation & Schema Validation | Masih berupa teks mentah monolith di `laravel.log`. |
| **Distributed Request Tracing** | **Not Implemented** | OpenTelemetry / W3C Trace Context Propagation | Belum ada correlation ID yang menghubungkan HTTP request ke Queue Job. |
| **Noisy-Neighbor Workload Isolation** | **Not Implemented** | Per-Tenant CPU/Query Quotas & Fair-Queue Scheduling | Belum ada throttling per tenant; berbagi satu pool database sama rata. |
| **Application Metrics (APM / SLO)** | **Not Implemented** | Prometheus / Datadog Real-time Latency Percentiles (p95/p99) | Belum ada metrik latensi p95/p99 atau instrumentasi APM. |

---

## 6. VERDICT & CONDITIONS

Domain 9 dinyatakan **NEEDS REMEDIATION**. Kecepatan query dan indexing database sangat solid, namun transisi menuju Structured JSON Logging, integrasi Correlation ID, dan mitigasi risiko Noisy-Neighbor wajib disiapkan untuk menjamin skalabilitas enterprise.
