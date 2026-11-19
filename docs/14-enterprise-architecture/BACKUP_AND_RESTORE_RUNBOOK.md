# RUNBOOK: Automated MariaDB Daily Backup, Retention & Disaster Recovery Drill
**Document ID**: RB-OPS-06  
**Scope**: MariaDB 11.4 Docker Stack (`apeiron_db`) on Production VPS (`103.247.11.166`)  
**Architecture Reference**: `TARGET_SYSTEM_ARCHITECTURE.md` §8.4–8.5 & `OPS-06`  
**Status**: ACTIVE PRODUCTION RUNBOOK  

---

## 1. RPO & RTO Service Level Objectives (SLOs)

| Metric | Target | Rationale & Mechanism |
| :--- | :--- | :--- |
| **Recovery Point Objective (RPO)** | **< 24 Jam (Scheduled)** / **< 5 Menit (WAL)** | Backup harian otomatis via `mariadb-dump` setiap pukul 02:00 WIB ditambah MariaDB redo log persistence pada volume Docker `mariadb_data`. |
| **Recovery Time Objective (RTO)** | **< 15 Menit** | Restorasi total 232 tabel & 57 trigger dari archive `.sql.gz` terkompresi ke database fresh menggunakan streaming `zcat \| mariadb`. |
| **Backup Window** | **Zero Lock Contention** | Menggunakan flag `--single-transaction --quick --lock-tables=false` sehingga transaksi kasir POS dan operasional toko berjalan 100% normal tanpa write lock. |

---

## 2. Automated Daily Backup Specification

### 2.1 Tooling & Execution Path
- **Script Host**: `/var/www/point-of-sales/docker/scripts/backup-db.sh`
- **Output Directory**: `/root/apeiron_backups/daily`
- **File Naming Pattern**: `apeiron_db_YYYYMMDD_HHMMSS.sql.gz` + `apeiron_db_YYYYMMDD_HHMMSS.sql.gz.sha256`
- **Retention**: **14 Hari** (otomatis dipangkas menggunakan `find ... -mtime +14 -delete`).

### 2.2 MariaDB Dump Flags Standard
```bash
mariadb-dump \
  -u root -p"${DB_ROOT_PASSWORD}" \
  --triggers \
  --routines \
  --events \
  --single-transaction \
  --quick \
  --lock-tables=false \
  "${DB_NAME}" | gzip -9 > "${BACKUP_FILE}"
```
- `--triggers`: Menjamin seluruh 57 trigger (financial immutability, cash variance, inventory movement) tersimpan utuh.
- `--routines` & `--events`: Menjaga stored procedures dan scheduled events.
- `--single-transaction`: Menjamin konsistensi snapshot ACID InnoDB tanpa mengunci tabel.

---

## 3. VPS Host Cron Installation

Pasang cron job pada user `root` VPS (`103.247.11.166`) agar berjalan otomatis setiap hari pukul 02:00 WIB:

```bash
# Tambahkan ke crontab root:
crontab -l | { cat; echo "0 2 * * * /var/www/point-of-sales/docker/scripts/backup-db.sh >> /var/log/apeiron-backup.log 2>&1"; } | crontab -
```

Untuk memeriksa status cron:
```bash
crontab -l
tail -n 50 /var/log/apeiron-backup.log
```

---

## 4. Verification & Quarterly Restore Drill Procedure

### 4.1 Automated Verification Script
Jalankan verifikasi integritas backup dan checksum SHA-256 kapan saja:
```bash
/var/www/point-of-sales/docker/scripts/verify-restore-drill.sh
```
Script melakukan:
1. Pengecekan kecocokan checksum SHA-256 (`.sha256`).
2. Uji dekompresi gzip (`gzip -t`).
3. Inspeksi struktur dump: memastikan >= 150 `CREATE TABLE` dan >= 40 `CREATE TRIGGER` statement ada di dalam dump.

### 4.2 Quarterly Live Restoration Drill (`--live-drill`)
Sesuai kepatuhan SOC-2 / ISO 27001 dan target enterprise architecture, lakukan live restore drill setiap kuartal ke scratch database:
```bash
/var/www/point-of-sales/docker/scripts/verify-restore-drill.sh --live-drill
```
Prosedur live drill otomatis:
1. Membuat temporary scratch database `apeiron_drill_<timestamp>` di dalam container `apeiron_db`.
2. Melakukan restore penuh dari dump archive terbaru.
3. Memvalidasi jumlah tabel yang ter-restore (target: >= 230 tabel) dan trigger (target: >= 55 trigger).
4. Menghapus database scratch secara atomik (`DROP DATABASE`) dan mencatat laporan status.

---

## 5. Emergency Disaster Recovery (DR) Full Restoration

Jika server utama mengalami kegagalan fatal dan database harus direstore secara penuh:

```bash
# 1. Hentikan container aplikasi agar tidak ada traffic masuk
cd /var/www/point-of-sales
docker compose stop app worker scheduler web

# 2. Ambil backup terbaru
LATEST_BACKUP=$(ls -t /root/apeiron_backups/daily/apeiron_db_*.sql.gz | head -n 1)

# 3. Verifikasi checksum
sha256sum -c "${LATEST_BACKUP}.sha256"

# 4. Restore ke database produksi
zcat "${LATEST_BACKUP}" | docker exec -i apeiron_db mariadb -u root -p"${DB_ROOT_PASSWORD}" point_of_sales

# 5. Jalankan migrasi pending (bila ada)
docker compose up -d app worker scheduler web
docker compose exec -T app php artisan migrate --force

# 6. Re-warm caches
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan optimize
docker compose exec -T app php artisan queue:restart
```
