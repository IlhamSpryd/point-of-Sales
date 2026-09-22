# EXECUTIVE SUMMARY: YOVEL COFFEE POS - PROTOCOL OMEGA
**Tech Stack:** Laravel 13, TALL Stack (Livewire/Alpine), MySQL 8 / MariaDB
**Architecture Standard:** Tier-1 Global Enterprise (McDonald's / Starbucks Parity)

## ✅ MODUL & FITUR YANG SUDAH SELESAI (IMPLEMENTED)
1. **Security & Core Backend (Node 1):** 
   - `lockForUpdate()` (Pessimistic Locking) dan 3-layer idempotency untuk transaksi.
   - Guard `resolveOpenShiftOrFail` (Zero-Trust Shift Validation) telah aktif menahan transaksi tanpa shift.
   - Kompensasi stok otomatis via tabel ledger `stock_movements`.
   - **Logika Shift Management:** Penegakan constraint 1 shift per kasir di level database (Virtual Column + Unique Index) dan pencegahan *race-condition* saat tutup shift.
   - **Core TransactionService Refactored** (BOM Deduction, Split Payment, Loyalty Points) dengan strict DB Transaction & Lock Hierarchy.
   - **Self-Order Workflows Unified:** Jalur penyelesaian pesanan tertunda via Webhook (Midtrans) dan Kasir (Pending Fulfillment) telah diarahkan ke `TransactionService` (mendukung Ledger `payments` dan mesin *Loyalty*).
2. **Frontend & Kiosk (Node 2):** 
   - Mode Kiosk/Fullscreen dengan layout terkunci (`pos-layout-locked`, `overscroll-none`, `no-select`).
   - Standardisasi touch-target 44px dan pencegahan double-submit via state Alpine.js.
   - Pemasangan selector deterministik (`dusk`, `data-testid`) untuk pengujian otomatis.
   - **UI Kiosk Refactor:** Integrasi Split Payment (exact-sum via Alpine.js) dan Pemilihan Pelanggan (Loyalty) di Kasir Livewire.
3. **Omnichannel Gateway (Node 5):**
   - Webhook GrabFood/GoFood aktif dengan validasi HMAC-SHA256 (5-minute skew).
   - Antrean asinkron menggunakan Background Jobs (`ProcessWebhookOrderJob`).
4. **QA Automation & CI/CD (Node 6):**
   - Pipeline GitHub Actions, PHPStan tingkat 5, dan `TransactionConcurrencyTest` untuk *Chaos Testing* database nyata.
3. **IoT Hardware & Performance (Fokus Node 3 & 4):**
   - Integrasi langsung ke ESC/POS (Thermal Printer) via QZ Tray dan *Cash Drawer Kick* telah diaktifkan sepenuhnya.

## 🚧 BACKLOG & FITUR YANG BELUM ADA (PENDING / IN PROGRESS)
1. **Manajemen Toko:**
   - Halaman pengaturan dinamis toko belum berfungsi sepenuhnya.
