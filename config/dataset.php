<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Apeiron POS — Demo Dataset Configuration
|--------------------------------------------------------------------------
|
| Sumber konfigurasi tunggal untuk generator dataset demo (`pos:dataset:generate`).
| Semua nilai bisa dioverride lewat opsi CLI maupun environment variable.
| JANGAN menyimpan password plaintext di sini; password demo dikirim lewat
| opsi `--password` (atau env `DATASET_DEMO_PASSWORD`) saat eksekusi.
|
| Parameter volume dibagi per akun demo (per tenant), bukan total gabungan.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Akun demo
    |----------------------------------------------------------------------
    | Setiap akun menjadi tenant INDEPENDEN (isolasi penuh antar akun).
    | Masing-masing tenant memiliki beberapa cabang (stores).
    */
    'demo_accounts' => [
        [
            'email' => 'ilhamsepriyadi8@gmail.com',
            'name' => 'Ilham Sepriyadi',
            'tenant_name' => 'Apeiron Coffee & Kitchen',
            'tenant_slug' => 'apeiron-coffee-kitchen',
            'stores' => [
                ['name' => 'Apeiron Senopati', 'code' => 'SEN-01', 'city' => 'Jakarta Selatan', 'address' => 'Jl. Senopati No. 42, Kebayoran Baru', 'phone' => '021-5208421', 'char' => 'flagship'],
                ['name' => 'Apeiron Kemang', 'code' => 'KMG-02', 'city' => 'Jakarta Selatan', 'address' => 'Jl. Kemang Raya No. 18, Mampang Prapatan', 'phone' => '021-7192210', 'char' => 'cafe'],
                ['name' => 'Apeiron SCBD', 'code' => 'SCD-03', 'city' => 'Jakarta Pusat', 'address' => 'Gedung Bursa Efek Tower 2, SCBD Lot 9', 'phone' => '021-51402233', 'char' => 'office'],
                ['name' => 'Apeiron PIK Avenue', 'code' => 'PIK-04', 'city' => 'Jakarta Utara', 'address' => 'Ruko Crown Golf Blok D, Pantai Indah Kapuk', 'phone' => '021-22570418', 'char' => 'mall'],
                ['name' => 'Apeiron BSD Green Office', 'code' => 'BSD-05', 'city' => 'Tangerang Selatan', 'address' => 'Green Office Park 9, BSD City', 'phone' => '021-29635110', 'char' => 'office'],
                ['name' => 'Apeiron Bandung Dago', 'code' => 'BDG-06', 'city' => 'Bandung', 'address' => 'Jl. Ir. H. Juanda No. 115, Dago', 'phone' => '022-2503388', 'char' => 'tourism'],
            ],
        ],
        [
            'email' => 'ilhamsepriyadi0@gmail.com',
            'name' => 'Ilham Sepriyadi (Bakery)',
            'tenant_name' => 'Sagara Bakehouse',
            'tenant_slug' => 'sagara-bakehouse',
            'stores' => [
                ['name' => 'Sagara Menteng', 'code' => 'MTG-01', 'city' => 'Jakarta Pusat', 'address' => 'Jl. Cikini Raya No. 27, Menteng', 'phone' => '021-31902211', 'char' => 'flagship'],
                ['name' => 'Sagara Panglima Polim', 'code' => 'PLP-02', 'city' => 'Jakarta Selatan', 'address' => 'Jl. Panglima Polim Raya No. 9, Melawai', 'phone' => '021-7392110', 'char' => 'cafe'],
                ['name' => 'Sagara Kelapa Gading', 'code' => 'KLG-03', 'city' => 'Jakarta Utara', 'address' => 'Boulevard Raya Blok QA No. 7, Kelapa Gading', 'phone' => '021-4508812', 'char' => 'mall'],
                ['name' => 'Sagara Bintaro', 'code' => 'BTR-04', 'city' => 'Tangerang Selatan', 'address' => 'Jl. Bintaro Utama 9 Blok JB, Pondok Aren', 'phone' => '021-74862280', 'char' => 'residential'],
                ['name' => 'Sagara Surabaya Darmo', 'code' => 'SBY-05', 'city' => 'Surabaya', 'address' => 'Jl. Raya Darmo No. 61, Wonokromo', 'phone' => '031-5672210', 'char' => 'tourism'],
            ],
        ],
    ],

    'demo_password' => env('DATASET_DEMO_PASSWORD'),

    /*
    |----------------------------------------------------------------------
    | Periode dataset
    |----------------------------------------------------------------------
    */
    'period' => [
        // Rentang default 12 bulan penuh yang berakhir pada bulan berjalan (inklusif).
        'months' => 12,
        'start_date' => env('DATASET_START_DATE'), // null => dihitung otomatis
        'end_date' => env('DATASET_END_DATE'),     // null => hari ini
        'timezone' => 'Asia/Jakarta',
        // Jam operasional per karakter cabang (jam lokal, 0-23).
        'opening_hour' => 7,
        'closing_hour' => 22,
    ],

    /*
    |----------------------------------------------------------------------
    | Reproducibility & batch
    |----------------------------------------------------------------------
    */
    'random_seed' => (int) env('DATASET_RANDOM_SEED', 20261023),
    'batch_size' => (int) env('DATASET_BATCH_SIZE', 500),
    // Jumlah hari per transaksi DB (checkpoint per hari) — batasi ukuran transaksi.
    'days_per_checkpoint' => (int) env('DATASET_DAYS_PER_CHECKPOINT', 1),
    // Jumlah hari per flush batch insert.
    'commit_every_days' => (int) env('DATASET_COMMIT_EVERY_DAYS', 1),

    /*
    |----------------------------------------------------------------------
    | Volume per tenant (per akun demo)
    |----------------------------------------------------------------------
    */
    'volume' => [
        // Jumlah cabang tetap mengikuti daftar `stores` di atas; nilai ini
        // hanya membatasi jumlah maksimum yang dipakai.
        'max_stores' => 6,
        // Pegawai per cabang (manager, supervisor, kasir, barista, cook, waiter, inventory).
        'employees_per_store' => 7,
        // Produk per tenant (item dari katalog internal generator).
        'products' => 120,
        // Varian SKU aktif (ukuran/rasa) yang ditambahkan pada subset produk.
        'product_variants' => 60,
        'categories' => 12,
        'ingredients' => 60,
        'customers' => 900,
        'suppliers' => 8,
        // Rata-rata transaksi per cabang per hari (weekday).
        'orders_per_store_per_day' => 60,
        // Target transaksi per hari per tenant (0 = hitung dari per-cabang).
        'orders_per_day' => 0,
        // Multiplier volume untuk weekend (Sabtu s/d Minggu).
        'weekend_multiplier' => 1.45,
        // Multiplier volume per karakter cabang.
        'store_char_multipliers' => [
            'flagship' => 1.35,
            'mall' => 1.20,
            'office' => 1.10,
            'cafe' => 1.00,
            'residential' => 0.85,
            'tourism' => 0.90,
        ],
        // Musim: nama bulan (1-12) => multiplier.
        'seasonal_multipliers' => [
            1 => 0.95, 2 => 1.02, 3 => 1.00, 4 => 1.05,
            5 => 1.02, 6 => 1.10, 7 => 1.08, 8 => 1.00,
            9 => 1.05, 10 => 1.10, 11 => 1.12, 12 => 1.25,
        ],
        // Aktivitas: multi-branch — sebagian cabang dibuka belakangan.
        'staggered_store_opening' => true,
        // Min/max item per transaksi beserta distribusinya (weights).
        'items_per_order' => [
            1 => 34, 2 => 28, 3 => 18, 4 => 10, 5 => 6, 6 => 4,
        ],
        // Qty per item: qty => weight.
        'qty_per_item' => [1 => 80, 2 => 15, 3 => 4, 4 => 1],
    ],

    /*
    |----------------------------------------------------------------------
    | Distribusi perilaku bisnis
    |----------------------------------------------------------------------
    */
    'behavior' => [
        // Proporsi order dengan pelanggan terdaftar (sisanya walk-in anonim).
        'customer_attached_rate' => 0.38,
        // Proporsi order memakai diskon aktif.
        'discount_rate' => 0.12,
        // Proporsi order dengan split payment (2 leg).
        'split_payment_rate' => 0.06,
        // Distribusi metode pembayaran.
        'payment_methods' => [
            'qris' => 34,
            'cash' => 28,
            'card' => 20,
            'ewallet' => 18,
        ],
        // Distribusi tipe order.
        'order_types' => [
            'dine_in' => 46,
            'takeaway' => 44,
            'delivery' => 10,
        ],
        // Proporsi order batal/void (tidak memotong omzet bersih).
        'void_rate' => 0.006,
        // Proporsi transaksi yang diretur (hanya bisa dari order paid yang cukup lama).
        'return_rate' => 0.012,
        // Jeda minimal sebelum retur (hari) dan maksimum.
        'return_window_days' => [0, 21],
        // Proporsi order dengan modifier pada item minuman.
        'modifier_rate' => 0.42,
        // Proporsi order yang memiliki cash drawer movement petty expense.
        'petty_cash_rate' => 0.35,
    ],

    /*
    |----------------------------------------------------------------------
    | Pembelian & persediaan
    |----------------------------------------------------------------------
    */
    'procurement' => [
        // Frekuensi purchase order per cabang per minggu.
        'po_per_store_per_week' => 3,
        // Rentang hari kedatangan barang setelah PO.
        'lead_time_days' => [1, 4],
        // Proporsi PO yang dibatalkan.
        'cancel_rate' => 0.03,
        // Proporsi baris yang diterima sebagian (short receipt).
        'partial_receipt_rate' => 0.15,
        // Stok awal per ingredient (kelipatan reorder level).
        'initial_stock_multiplier' => 3.0,
        // Proporsi produk retail (non-BOM) yang dijual per cabang.
        'retail_stock_initial' => 60,
        'daily_opname_rate' => 0.02,
    ],

    /*
    |----------------------------------------------------------------------
    | Akun & keamanan (TIDAK menyimpan password di config)
    |----------------------------------------------------------------------
    */
    'accounts' => [
        'password_env_key' => 'DATASET_DEMO_PASSWORD',
        'cashier_pin' => env('DATASET_CASHIER_PIN', '135790'), // PIN kasir demo (bukan password login)
        'plan_code' => 'enterprise',
    ],

    /*
    |----------------------------------------------------------------------
    | Penanda sumber dataset
    |----------------------------------------------------------------------
    | Marker stabil untuk membedakan baris sintetis dari data operasional.
    | Disimpan pada kolom yang tersedia di schema (tanpa perubahan skema):
    |   - tenants.provisioning_idempotency_key  => 'dataset:<run_id>:<slug>'
    |   - orders.idempotency_key prefix          => 'ds-<run_id>-...'
    |   - products.product_code prefix           => 'DS<tenant>-...'
    |   - stock feed idempotency prefix          => 'ds:<run_id>:...'
    */
    'marker' => [
        'prefix' => 'ds',
        'run_id' => env('DATASET_RUN_ID'),
    ],

    /*
    |----------------------------------------------------------------------
    | Batas pengaman (guard) target database
    |----------------------------------------------------------------------
    */
    'safety' => [
        // Wajib sama dengan nama database aktual (`--confirm-database`).
        'required_confirm' => true,
        // Tolak jika environment produksi/staging walau DB terlihat lokal.
        'forbidden_environments' => ['production', 'prod', 'staging'],
        // Nama database yang secara eksplisit dianggap disposable/demo.
        'allowed_database_patterns' => [
            '*demo*', '*dataset*', '*test*', '*disposable*', 'point_of_sales',
        ],
        // Database yang tidak boleh pernah disentuh.
        'forbidden_database_patterns' => ['*prod*', '*production*', '*live*', '*shared*'],
        // Operasi apa pun yang menimpa data operasional harus ditolak.
        'allow_existing_operational_data' => false,
    ],
];
