# Production Mail Configuration & Security Deployment Guide

**Target System:** Apeiron System — Multi-Tenant SaaS Point of Sale  
**Applies to:** Production Deployment, Staging, and Infrastructure Operations  
**Compliance Standard:** Domain 1 Data Isolation & PII Protection (SEC-003, SEC-016, OR-012)

---

## 1. Overview & Security Mandate

Apeiron System handles sensitive operational and administrative emails, including:
- Owner Onboarding magic links and tokens (`owner_onboarding_tokens`)
- User password-reset links and verification tokens
- Order notification receipts containing customer details (PII)

Dalam lingkungan produksi (`APP_ENV=production`), **driver mailer `log` DILARANG KERAS**. Menulis konten email transaksi ke log file server berisiko tinggi membocorkan rahasia autentikasi dan data pribadi pelanggan ke log aggregators (seperti CloudWatch, Datadog, atau file log publik).

Aplikasi mengimplementasikan fail-fast boot guard di `AppServiceProvider` yang akan melempar `RuntimeException` dan mematikan inisialisasi aplikasi jika `log` terdeteksi pada konfigurasi mail produksi.

---

## 2. Aturan Transport Mail Produksi

### 2.1 Konfigurasi `MAIL_MAILER` Wajib Nyata
Variabel `MAIL_MAILER` pada file `.env` produksi wajib diarahkan ke transport email transaksional yang aktif dan tervalidasi.

Contoh konfigurasi yang diizinkan di produksi:
```env
# Contoh: SMTP Terotentikasi (TLS Port 587 atau SSL Port 465)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=postmaster@mg.apeironpos.id
MAIL_PASSWORD=your-secret-smtp-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@apeironpos.id"
MAIL_FROM_NAME="Apeiron System"

# Contoh alternatif: Driver API khusus (SES, Resend, Postmark)
# MAIL_MAILER=ses
# MAIL_MAILER=resend
# MAIL_MAILER=postmark
```

### 2.2 Larangan `log` Sebagai Default Maupun Anggota Chain Failover
`AppServiceProvider` melakukan inspeksi rekursif terhadap mailer default dan seluruh anggota grup:
- Jika `MAIL_MAILER=log`, boot aplikasi ditolak (`exit 1` / HTTP 500 fatal).
- Jika `MAIL_MAILER=failover` atau `MAIL_MAILER=roundrobin`, dan salah satu anak mailer di dalam chain mengarah ke `log`, boot aplikasi **tetap ditolak**.

Konfigurasi seperti berikut dilarang di produksi:
```php
// DILARANG DI PRODUKSI:
'mailers' => [
    'failover' => [
        'transport' => 'failover',
        'mailers' => ['smtp', 'log'], // 'log' di sini akan memicu RuntimeException!
    ],
]
```

### 2.3 Risiko Penggunaan Driver `sendmail`
Driver `sendmail` menggunakan binary MTA lokal pada server hosting (`/usr/sbin/sendmail`). Meskipun tidak ditolak oleh guard `log`, **`sendmail` BUKAN jaminan delivery yang andal** untuk sistem SaaS enterprise:
- **Ketiadaan Reputasi IP & DKIM/SPF:** Email yang dikirim via MTA lokal container/VM sering langsung ditolak atau masuk ke folder spam oleh Gmail/Yahoo/Outlook.
- **Ketiadaan Feedback Loop:** Gagal kirim (bounce / deferred) tidak tercatat di level aplikasi, menyebabkan pelanggan atau Owner tidak menerima token reset sandi tanpa peringatan apa pun.
- **Rekomendasi:** Gunakan SMTP tersertifikasi atau Transactional Email Service resmi.

---

## 3. Checklist Validasi Pra-Deployment (Pre-flight Gate)

Sebelum kontainer baru dialihkan ke traffic aktif (blue/green deployment atau rolling update), tim DevOps/SRE wajib menjalankan verifikasi berikut:

1. **Pastikan Environment Variable Terpasang:**
   ```bash
   php artisan tinker --execute="echo config('mail.default');"
   # Pastikan output BUKAN 'log'
   ```

2. **Uji Booting Provider di Environment Produksi:**
   ```bash
   APP_ENV=production php artisan config:clear
   APP_ENV=production php artisan about
   # Pastikan perintah di atas keluar dengan exit code 0 tanpa RuntimeException
   ```

3. **Verifikasi Database Integrity:**
   ```bash
   php artisan pos:verify-database
   # Pastikan seluruh 10 skrip verifikasi lulus (exit code 0)
   ```

4. **Larangan Commit File `.env` Produksi:**
   Pastikan kredensial SMTP, API key Midtrans, dan kunci database hanya dikelola melalui Secret Manager (misal: AWS Secrets Manager, GitHub Secrets, Vault).
