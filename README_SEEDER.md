# 🏪 Yovel Coffee & Cafe — POS Data Seeder

## Deskripsi

Seeder ini menghasilkan **data realistis 12 bulan penuh** untuk sistem POS "Yovel Coffee & Cafe", sebuah cafe & resto modern di Jakarta Selatan yang menyajikan specialty coffee, Indonesian comfort food, dan dessert.

### Volume Data yang Dihasilkan

| Tabel | Estimasi Rows | Deskripsi |
|-------|--------------|-----------|
| `users` | ~33 | 4 existing + 29 new employees |
| `categories` | 11 | Kategori menu |
| `products` | 133 | Menu lengkap |
| `ingredients` | 65 | Bahan baku |
| `product_ingredients` | ~350 | Resep per produk |
| `modifier_groups` | 8 | Grup modifier |
| `modifiers` | ~27 | Opsi modifier |
| `tables` | 30 | Meja di 6 area |
| `customers` | 1,000 | 15% soft-deleted |
| `loyalty_tiers` | 5 | Bronze → Diamond |
| `discounts` | 15 | Promo realistis |
| `shifts` | ~3,500-4,500 | 365 hari × 3 shift |
| `orders` | **~50,000** | Weekday 80-150/hari, Weekend 180-280/hari |
| `order_items` | **~150,000** | 1-6 item per order |
| `order_item_modifiers` | **~100,000+** | Modifier per item |
| `payments` | **~55,000** | 1-2 per order (10% split) |
| `stock_movements` | **~140,000** | Per completed order item |
| `ingredient_stock_movements` | **~400,000+** | Per ingredient per item + restock |
| `loyalty_ledger` | **~30,000** | Earn + redeem |
| `customer_loyalty_accounts` | ~600 | Saldo & tier |
| `cash_drawer_movements` | **~15,000** | 3-5 per shift |
| `activity_logs` | 8,000 | Login, order, shift, dll |

---

## 🚀 Cara Menjalankan

### Fresh Install (Recommended)
```bash
php artisan migrate:fresh --seed
```

### Hanya Seed Data (tanpa reset migrations)
```bash
php artisan db:seed --class=PosFullSeeder
```

### Seed Satu Tabel Tertentu
```bash
php artisan db:seed --class="Database\\Seeders\\Pos\\ProductSeeder"
```

---

## ⏱️ Estimasi Waktu Eksekusi

| Hardware | Estimasi |
|----------|----------|
| SSD + 16GB RAM + i7/i9 | **8-12 menit** |
| SSD + 8GB RAM + i5 | **12-18 menit** |
| HDD + 8GB RAM | **20-30 menit** |

Seeder menggunakan `set_time_limit(0)` dan `memory_limit=2G` untuk phase berat.

---

## 🔄 Cara Rollback

```bash
# Full reset — hapus semua data & migrasi, lalu seed ulang
php artisan migrate:fresh --seed

# Atau hanya reset data tanpa migrasi (manual truncate)
php artisan tinker --execute "DB::statement('SET FOREIGN_KEY_CHECKS=0'); collect(DB::select('SHOW TABLES'))->each(fn(\$t) => DB::table(array_values((array)\$t)[0])->truncate()); DB::statement('SET FOREIGN_KEY_CHECKS=1');"
```

---

## 📋 Urutan Seeding (FK-safe)

```
Phase 1: Master Data
├── RoleSeeder          → roles (8 roles)
├── UserSeeder          → users (33 employees)
├── CategorySeeder      → categories (11)
├── ProductSeeder       → products (133)
├── IngredientSeeder    → ingredients (65)
└── ProductIngredientSeeder → product_ingredients (~350)

Phase 2: Modifiers & Venue
├── ModifierSeeder      → modifier_groups + modifiers
├── ModifierGroupProductSeeder → modifier_group_product
├── ModifierIngredientSeeder   → modifier_ingredients
└── TableSeeder         → tables (30)

Phase 3: Customers & Loyalty
├── LoyaltyTierSeeder   → loyalty_tiers (5)
├── CustomerSeeder      → customers (1000)
└── DiscountSeeder      → discounts (15)

Phase 4: Shifts
└── ShiftSeeder         → shifts (~4000)

Phase 5: Orders (HEAVIEST)
└── OrderSeeder         → orders + order_items + order_item_modifiers
                        + payments + stock_movements (all in one)

Phase 6: Ingredient Movements
└── IngredientStockMovementSeeder → ingredient_stock_movements (~400K)

Phase 7: Loyalty
└── LoyaltyLedgerSeeder → loyalty_ledger + customer_loyalty_accounts

Phase 8: Supporting Data
├── CashDrawerMovementSeeder → cash_drawer_movements
└── ActivityLogSeeder   → activity_logs
```

---

## ⚡ Tips Performa

### Sebelum Seeding (Opsional — untuk speed)

1. **Naikkan `max_allowed_packet`** di MySQL/MariaDB:
   ```ini
   # my.ini atau my.cnf
   max_allowed_packet = 256M
   ```

2. **Naikkan `innodb_buffer_pool_size`**:
   ```ini
   innodb_buffer_pool_size = 1G
   ```

3. **Matikan query log** sementara:
   ```sql
   SET GLOBAL general_log = 'OFF';
   SET GLOBAL slow_query_log = 'OFF';
   ```

### PHP Configuration
Seeder otomatis mengatur:
- `set_time_limit(0)` — tidak ada timeout
- `ini_set('memory_limit', '2G')` — cukup RAM untuk batch

---

## ⚠️ Catatan Penting

### Trigger Management
Beberapa seeder **sementara men-drop dan men-recreate triggers** pada tabel append-only:
- `loyalty_ledger` → 4 triggers (hash chain, sync, no-update, no-delete)
- `ingredient_stock_movements` → 2 triggers (no-update, no-delete)

Triggers di-recreate otomatis setelah seeding selesai. Jangan interrupt proses di tengah jalan.

### Payment Trigger
Trigger `trg_payments_prevent_overpayment` tetap aktif. Seeder memastikan:
- Payment amount == order_amount (single payment)
- Split payments: amount1 + amount2 == order_amount

### Generated Columns
Kolom berikut **TIDAK diisi manual** (MySQL auto-generate):
- `categories.name_uniqueness_key`
- `users.email_uniqueness_key`
- `customers.phone_uniqueness_key`
- `tables.table_name_uniqueness_key`
- `shifts.open_shift_lock_key`

### Shift Constraint
`shifts_one_open_per_user_unique` — hanya 1 shift `status='open'` per user.
Seeder memastikan constraint ini terpenuhi.

---

## 🔍 Validasi Setelah Seeding

Seeder otomatis menampilkan:
- Row count semua tabel
- Monthly revenue breakdown
- Top 10 produk terlaris

Untuk validasi manual:
```sql
-- Total orders per bulan
SELECT DATE_FORMAT(order_date, '%Y-%m') AS bulan,
       COUNT(*) AS orders,
       SUM(order_amount) AS revenue
FROM orders
WHERE order_status = 'completed'
GROUP BY bulan ORDER BY bulan;

-- Top 10 terlaris
SELECT p.product_name, SUM(oi.qty) AS sold
FROM order_items oi
JOIN products p ON p.id = oi.product_id
JOIN orders o ON o.id = oi.order_id
WHERE o.order_status = 'completed'
GROUP BY p.product_name
ORDER BY sold DESC LIMIT 10;

-- Revenue per payment method
SELECT payment_method, COUNT(*) AS trx, SUM(order_amount) AS total
FROM orders WHERE order_status = 'completed'
GROUP BY payment_method ORDER BY total DESC;
```
