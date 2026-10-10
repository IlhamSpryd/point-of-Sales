# Domain 1 — Provisioning API Contract

Endpoint ini dikonsumsi oleh Website Marketing (TanStack Start) secara server-to-server (S2S) untuk membuat tenant baru di Apeiron POS.

## Environment Variables (Marketing Server)
```env
# URL absolut API POS.
APEIRON_POS_API_URL="https://pos.example.com/api/v1/provisioning/registrations"

# Secret provisioning yang didapat dari tim POS. Harus dijaga server-side,
# tidak boleh bocor ke client-side / browser.
APEIRON_POS_PROVISIONING_SECRET="your-secret-here"
```

## Endpoint Contract

**`POST /api/v1/provisioning/registrations`**
- **Rate limit:** 10 requests / 1 menit (karena memicu resource creation DB).
- **Format:** JSON.
- **Query strings:** Ditolak sepenuhnya (`400 Bad Request`).

### Headers Wajib
| Header | Keterangan |
|--------|------------|
| `Content-Type` | Harus `application/json` |
| `X-Timestamp` | Unix epoch time (integer detik, misal `1696238100`). Toleransi ±300 detik. |
| `X-Nonce` | String unik (misal UUID) yang belum pernah dipakai dalam 30 menit terakhir. |
| `X-Idempotency-Key` | String 16–64 karakter (UUID sangat direkomendasikan). Mengontrol retry dan mencegah pembuatan tenant ganda akibat jaringan. |
| `X-Signature` | HMAC-SHA256 hash (huruf kecil, 64 karakter hex). Lihat algoritma penandatanganan di bawah. |

### Payload JSON (Allowlist)
```json
{
  "tenant_name": "Kopi Cepat", // Wajib, string 2-120 chars
  "owner_name": "Andi Wijaya", // Wajib, string 2-120 chars
  "owner_email": "andi@example.com", // Wajib, format email valid
  "owner_phone": "081234567890" // Opsional
}
```
Field lain (seperti `tenant_id`, `role_id`, dsb) diabaikan secara ketat oleh server.

---

## Response Statuses

### `201 Created`
Provisioning berhasil (pertama kali dibuat).
```json
{
  "success": true,
  "message": "Provisioning berhasil. Email onboarding akan dikirim ke Owner.",
  "tenant_id": 105,
  "owner_user_id": 34,
  "store_id": 21,
  "onboarding": {
    "email_sent_to": "andi@example.com"
  }
}
```
*Note: Tidak ada token/link di-response. Token dikirim via email untuk keamanan.*

### `200 OK` (Idempotent Replay)
Request dengan `X-Idempotency-Key` yang *sama* dikirim ulang dan berhasil sebelumnya. Data yang dikembalikan adalah data asli tenant yang sudah dibuat.
```json
{
  "success": true,
  "message": "Provisioning sudah selesai sebelumnya (idempotent).",
  "tenant_id": 105,
  "owner_user_id": 34,
  "idempotent_replay": true
}
```

### `200 OK` (Pending Retryable)
Request dengan key sama tiba selagi server masih memproses request aslinya.
```json
{
  "success": true,
  "message": "Provisioning sedang diproses.",
  "status": "pending",
  "retryable": true
}
```
*Tindakan Marketing:* Tunggu beberapa detik, lalu retry dengan idempotency key dan signature yang sama.

### `409 Conflict` (Bussiness/State Rules)
- **Email sudah terdaftar:**
  ```json
  {"success": false, "error": "email_already_in_use", "message": "Email tersebut sudah terdaftar..."}
  ```
  *(Catatan: 409 pada idempotency key lama di mana payload isinya beda dari payload asli akan mengembalikan pesan error `idempotency_key_conflict`)*.

### `401 Unauthorized` / `400 Bad Request`
Kesalahan Signature, Timestamp expired, atau Nonce replayed. Error body akan berisi info generik agar tidak leaking alasan kegagalan auth.

---

## Algoritma Penandatanganan (TS/Node.js)

Berikut adalah algoritma yang harus dipasang di server (TanStack handler):

```typescript
import { createHmac, createHash } from 'crypto';

const method = 'POST';
const path = '/api/v1/provisioning/registrations';
const bodyString = JSON.stringify(payload); // Pastikan key terurut atau jangan diubah setelah ini
const timestamp = Math.floor(Date.now() / 1000).toString();
const nonce = crypto.randomUUID();
const idempotencyKey = crypto.randomUUID(); // Jika membuat session registrasi baru

const bodyHash = createHash('sha256').update(bodyString).digest('hex');

// Canonical format: METHOD + \n + PATH + \n + TIMESTAMP + \n + NONCE + \n + SHA256(BODY)
const canonical = [method, path, timestamp, nonce, bodyHash].join('\n');

const signature = createHmac('sha256', process.env.APEIRON_POS_PROVISIONING_SECRET)
  .update(canonical)
  .digest('hex'); // lowercase hex
```
*Lalu gunakan variabel `timestamp`, `nonce`, `idempotencyKey`, dan `signature` sebagai Header pada `fetch` request.*

## Penanganan Retry di Marketing

- **Timeout/Socket Hangup**: Boleh *retry* request tersebut secara utuh, pertahankan **`X-Idempotency-Key`** yang sama agar server POS mengenalinya, namun generate **`X-Timestamp`**, **`X-Nonce`**, dan **`X-Signature`** yang baru agar tidak ditolak sebagai replay transport.
- **5xx Internal Server Error**: Sama seperti timeout, boleh diretry (dijamin aman karena rollback DB).

(Drafted for Phase 1 Integration)
