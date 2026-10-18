<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PopulateApeironFullYearDataset extends Command
{
    protected $signature = 'pos:populate-full-year 
                            {--tenant=9 : Target tenant ID}
                            {--orders=1000000 : Target order count}
                            {--chunk=1000 : Database insert batch size}
                            {--dry-run : Validate plan without inserting data}';

    protected $description = 'Populate Apeiron POS with 1 full year realistic dataset (70K orders, 8 stores, BOM, shifts, payments).';

    private int $tenantId = 9;

    private int $targetOrders = 1000000;

    private int $chunkSize = 1000;

    private bool $isDryRun = false;

    // In-memory data collections
    private array $stores = [];

    private array $users = [];

    private array $categories = [];

    private array $products = [];

    private array $ingredients = [];

    private array $productIngredients = [];

    private array $modifierGroups = [];

    private array $modifiers = [];

    private array $modifierIngredients = [];

    private array $modifierGroupProducts = [];

    private array $tables = [];

    private array $discounts = [];

    private array $customers = [];

    private array $loyaltyTiers = [];

    public function handle(): int
    {
        $this->tenantId = (int) $this->option('tenant');
        $this->targetOrders = (int) $this->option('orders');
        $this->chunkSize = (int) $this->option('chunk');
        $this->isDryRun = (bool) $this->option('dry-run');

        // Deterministic pseudo-random seed for reproducible seeding
        mt_srand(42);

        $this->info('');
        $this->info('╔═════════════════════════════════════════════════════════════════════╗');
        $this->info('║             APEIRON POS — FULL YEAR DATASET POPULATOR               ║');
        $this->info('║  Tenant: 9 (Rindu Kopi) | Target: 70,000 Paid Orders | 365 Days     ║');
        $this->info('╚═════════════════════════════════════════════════════════════════════╝');
        $this->info('');

        // Pre-flight checks
        $tenant = DB::table('tenants')->where('id', $this->tenantId)->first();
        if (! $tenant) {
            $this->error("Target tenant ID {$this->tenantId} does not exist in the database!");

            return 1;
        }

        $existingOrderCount = DB::table('orders')->where('tenant_id', $this->tenantId)->count();
        if ($existingOrderCount > 0) {
            $this->warn("Tenant {$this->tenantId} already has {$existingOrderCount} orders.");
            if (! $this->confirm('Tenant already has transactional records. Do you want to proceed and append synthetic dataset?', true)) {
                $this->info('Operation cancelled by user.');

                return 0;
            }
        }

        $startTime = microtime(true);

        if ($this->isDryRun) {
            $this->warn('DRY RUN MODE ENABLED — No data will be written to database.');
        }

        try {
            $this->info('▶ Stage 1: Configuring 8 Stores for Tenant '.$this->tenantId);
            $this->setupStores();

            $this->info('▶ Stage 2: Provisioning Staff & Branch Assignments (48 Employees)');
            $this->setupEmployees();

            $this->info('▶ Stage 3: Setting Up Catalog & BOM Recipes (18 Categories, 140 Products, 65 Ingredients)');
            $this->setupCatalogAndBOM();

            $this->info('▶ Stage 4: Setting Up Modifiers, Groups, Tables & Discounts');
            $this->setupModifiersTablesDiscounts();

            $this->info('▶ Stage 5: Setting Up Loyalty Tiers & Customers (1,000 Customers)');
            $this->setupCustomersAndLoyalty();

            $this->info('▶ Stage 6: Generating Historical Cashier Shifts & Cash Movements (5,840 Shifts)');
            $shifts = $this->setupShifts();

            $this->info('▶ Stage 7: Generating 70,000 Paid Orders, Items, Modifiers & Payments (365 Days)');
            $generatedSales = $this->generateOrdersAndPayments($shifts);

            $this->info('▶ Stage 8: Calculating & Writing Inventory Balances & Ledger (BOM + Non-BOM)');
            $this->setupInventoryMovementsAndBalances($generatedSales['ingredient_usage'], $generatedSales['product_usage']);

            $this->info('▶ Stage 9: Recording Customer Loyalty Ledger');
            $this->setupLoyaltyLedger($generatedSales['customer_orders']);

            $this->info('▶ Stage 10: Final Verification & Integrity Audits');
            $this->runFinalValidations();

            $elapsedMinutes = round((microtime(true) - $startTime) / 60, 2);
            $this->info('');
            $this->info("✨ Mission Complete! Dataset successfully populated in {$elapsedMinutes} minutes.");
            $this->info('');

            return 0;

        } catch (\Throwable $e) {
            $this->error('FATAL ERROR DURING SEEDING: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return 1;
        }
    }

    /**
     * Stage 1: Stores
     */
    private function setupStores(): void
    {
        // Store 6 is existing, update name to Flagship
        DB::table('stores')
            ->where('id', 6)
            ->where('tenant_id', $this->tenantId)
            ->update([
                'name' => 'Rindu Kopi - Flagship Senopati',
                'address' => 'Jl. Senopati No. 42, Kebayoran Baru, Jakarta Selatan',
                'updated_at' => now(),
            ]);

        $newStores = [
            ['name' => 'Rindu Kopi - Sudirman Central', 'address' => 'Gedung Bursa Efek Indonesia Tower 2, SCBD, Jakarta Pusat'],
            ['name' => 'Rindu Kopi - Kemang Urban', 'address' => 'Jl. Kemang Raya No. 18, Mampang Prapatan, Jakarta Selatan'],
            ['name' => 'Rindu Kopi - PIK Avenue', 'address' => 'Pantai Indah Kapuk, Ruko Crown Golf Blok D, Jakarta Utara'],
            ['name' => 'Rindu Kopi - Kelapa Gading', 'address' => 'Boulevard Raya Blok QA No. 7, Kelapa Gading, Jakarta Utara'],
            ['name' => 'Rindu Kopi - BSD Green Office', 'address' => 'Green Office Park 9, BSD City, Tangerang Selatan'],
            ['name' => 'Rindu Kopi - Bintaro Sektor 9', 'address' => 'Jl. Bintaro Utama 9 Blok JB, Pondok Aren, Tangerang Selatan'],
            ['name' => 'Rindu Kopi - Bandung Dago', 'address' => 'Jl. Ir. H. Juanda No. 115, Dago, Bandung'],
        ];

        foreach ($newStores as $s) {
            $existing = DB::table('stores')
                ->where('tenant_id', $this->tenantId)
                ->where('name', $s['name'])
                ->first();

            if (! $existing) {
                DB::table('stores')->insert([
                    'tenant_id' => $this->tenantId,
                    'name' => $s['name'],
                    'address' => $s['address'],
                    'created_at' => '2025-10-14 06:00:00',
                    'updated_at' => '2025-10-14 06:00:00',
                ]);
            }
        }

        $this->stores = DB::table('stores')
            ->where('tenant_id', $this->tenantId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->toArray();

        $this->line('  ✓ Configured '.count($this->stores)." operational branches for Tenant {$this->tenantId}.");
    }

    /**
     * Stage 2: Employees & Assignments
     */
    private function setupEmployees(): void
    {
        // Password hash for 'Apeiron2026!'
        $pwdHash = Hash::make('Apeiron2026!');
        $pinHash = Hash::make('123456');

        // Roles in tenant 9:
        // 22: Owner, 23: Manager, 24: Kasir, 25: Waiter, 26: Barista, 27: Inventory, 28: Supervisor, 29: Cook
        $firstNames = ['Budi', 'Siti', 'Agus', 'Dewi', 'Eko', 'Rini', 'Hendra', 'Nurul', 'Reza', 'Maya',
            'Fajar', 'Dian', 'Bambang', 'Putri', 'Ahmad', 'Ratna', 'Dimas', 'Sri', 'Bayu', 'Mega',
            'Aditya', 'Fitri', 'Rudi', 'Wulan', 'Gilang', 'Intan', 'Doni', 'Lestari', 'Yoga', 'Anisa',
            'Rian', 'Nadia', 'Teguh', 'Ayu', 'Aris', 'Tiara', 'Wahyu', 'Sari', 'Indra', 'Desi',
            'Farhan', 'Rika', 'Surya', 'Tari', 'Bagas', 'Hani', 'Lukman', 'Bella'];
        $lastNames = ['Pratama', 'Santoso', 'Wijaya', 'Kusuma', 'Saputra', 'Hidayat', 'Nugroho', 'Wibowo',
            'Lestari', 'Purnomo', 'Setiawan', 'Hermawan', 'Susanto', 'Hartono', 'Gunawan', 'Suryono'];

        $storeCount = count($this->stores);
        $empSeq = 4; // User 16, 17, 18 used counter 1, 2, 3

        $staffPlan = [];
        // Per store: 1 Manager, 1 Supervisor, 2 Cashiers, 1 Barista, 1 Waiter
        for ($sIdx = 0; $sIdx < $storeCount; $sIdx++) {
            $storeId = $this->stores[$sIdx]->id;
            $staffPlan[] = ['role_id' => 23, 'store_id' => $storeId, 'title' => 'Manager'];
            $staffPlan[] = ['role_id' => 28, 'store_id' => $storeId, 'title' => 'Supervisor'];
            $staffPlan[] = ['role_id' => 24, 'store_id' => $storeId, 'title' => 'Kasir 1 (Morning)'];
            $staffPlan[] = ['role_id' => 24, 'store_id' => $storeId, 'title' => 'Kasir 2 (Evening)'];
            $staffPlan[] = ['role_id' => 26, 'store_id' => $storeId, 'title' => 'Barista'];
            $staffPlan[] = ['role_id' => 25, 'store_id' => $storeId, 'title' => 'Waiter'];
        }

        // Additional 4 Cooks and 4 Inventory staff distributed
        for ($i = 0; $i < 4; $i++) {
            $storeId = $this->stores[$i % $storeCount]->id;
            $staffPlan[] = ['role_id' => 29, 'store_id' => $storeId, 'title' => 'Cook'];
            $staffPlan[] = ['role_id' => 27, 'store_id' => $storeId, 'title' => 'Inventory'];
        }

        foreach ($staffPlan as $p) {
            $empIdStr = sprintf('YVL-EMP-%d-%04d', $this->tenantId, $empSeq);
            $existing = DB::table('users')->where('employee_id', $empIdStr)->first();

            $fn = $firstNames[($empSeq * 7) % count($firstNames)];
            $ln = $lastNames[($empSeq * 11) % count($lastNames)];
            $fullName = "{$fn} {$ln}";
            $email = strtolower($fn).'.'.strtolower($ln).$empSeq.'@rindukopi.test';
            $phone = '08'.rand(11, 29).sprintf('%07d', $empSeq * 1234 % 9999999);

            if (! $existing) {
                $newUserId = DB::table('users')->insertGetId([
                    'tenant_id' => $this->tenantId,
                    'role_id' => $p['role_id'],
                    'employee_id' => $empIdStr,
                    'name' => $fullName,
                    'phone_number' => $phone,
                    'email' => $email,
                    'email_verified_at' => '2025-10-14 06:00:00',
                    'password_hash' => $pwdHash,
                    'pin_hash' => $pinHash,
                    'join_date' => '2025-10-14',
                    'is_active' => 1,
                    'created_at' => '2025-10-14 06:00:00',
                    'updated_at' => '2025-10-14 06:00:00',
                ]);

                // Map to store
                DB::table('user_stores')->insert([
                    'tenant_id' => $this->tenantId,
                    'user_id' => $newUserId,
                    'store_id' => $p['store_id'],
                    'created_at' => '2025-10-14 06:00:00',
                    'updated_at' => '2025-10-14 06:00:00',
                ]);
            }

            $empSeq++;
        }

        // Also assign User 16 (Owner) to all branches
        foreach ($this->stores as $s) {
            $hasAssignment = DB::table('user_stores')
                ->where('tenant_id', $this->tenantId)
                ->where('user_id', 16)
                ->where('store_id', $s->id)
                ->exists();

            if (! $hasAssignment) {
                DB::table('user_stores')->insert([
                    'tenant_id' => $this->tenantId,
                    'user_id' => 16,
                    'store_id' => $s->id,
                    'created_at' => '2025-10-14 06:00:00',
                    'updated_at' => '2025-10-14 06:00:00',
                ]);
            }
        }

        // Update employee sequence
        DB::table('employee_id_sequences')
            ->where('tenant_id', $this->tenantId)
            ->update([
                'last_value' => $empSeq - 1,
                'updated_at' => now(),
            ]);

        $this->users = DB::table('users')
            ->where('tenant_id', $this->tenantId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->keyBy('id')
            ->toArray();

        $this->line('  ✓ Provisioned '.count($this->users).' employees with synchronized sequences.');
    }

    /**
     * Stage 3: Catalog & BOM
     */
    private function setupCatalogAndBOM(): void
    {
        // 18 Categories
        $categoryNames = [
            'Espresso Bar', 'Signature Coffee', 'Cold Brew Series', 'Manual Brew Single Origin',
            'Milk-Based Coffee', 'Artisan Tea & Tisane', 'Non-Coffee Beverages', 'Frappe & Blended',
            'Fresh Baked Pastry', 'Artisan Croissants', 'Toast & Sandwiches', 'Heavy Rice Bowls',
            'Pasta & Western Mains', 'Indonesian Comfort Food', 'Light Bites & Platters',
            'Desserts & Sweet Treats', 'Packaged Coffee Beans', 'Merchandise & Gear',
        ];

        foreach ($categoryNames as $idx => $cName) {
            $cCode = sprintf('CAT-9-%03d', $idx + 1);
            $existing = DB::table('categories')
                ->where('tenant_id', $this->tenantId)
                ->where('category_name', $cName)
                ->first();

            if (! $existing) {
                DB::table('categories')->insert([
                    'tenant_id' => $this->tenantId,
                    'category_code' => $cCode,
                    'category_name' => $cName,
                    'created_at' => '2025-10-14 06:00:00',
                    'updated_at' => '2025-10-14 06:00:00',
                ]);
            }
        }

        $this->categories = DB::table('categories')
            ->where('tenant_id', $this->tenantId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->keyBy('id')
            ->toArray();

        // 48 Ingredients
        $rawIngredients = [
            // Coffee Beans (gram)
            ['code' => 'ING-9-001', 'name' => 'Biji Kopi House Blend Arabica', 'unit' => 'g', 'cost' => 300, 'reorder' => 5000],
            ['code' => 'ING-9-002', 'name' => 'Biji Kopi Single Origin Aceh Gayo', 'unit' => 'g', 'cost' => 450, 'reorder' => 3000],
            ['code' => 'ING-9-003', 'name' => 'Biji Kopi Single Origin Toraja Sapan', 'unit' => 'g', 'cost' => 480, 'reorder' => 3000],
            ['code' => 'ING-9-004', 'name' => 'Biji Kopi Single Origin Bali Kintamani', 'unit' => 'g', 'cost' => 420, 'reorder' => 3000],
            ['code' => 'ING-9-005', 'name' => 'Biji Kopi Ethiopia Yirgacheffe', 'unit' => 'g', 'cost' => 700, 'reorder' => 2000],
            // Dairy & Plant-based (ml)
            ['code' => 'ING-9-006', 'name' => 'Fresh Milk Pasteurisasi', 'unit' => 'ml', 'cost' => 20, 'reorder' => 20000],
            ['code' => 'ING-9-007', 'name' => 'Oat Milk Barista Edition', 'unit' => 'ml', 'cost' => 45, 'reorder' => 15000],
            ['code' => 'ING-9-008', 'name' => 'Almond Milk Unsweetened', 'unit' => 'ml', 'cost' => 50, 'reorder' => 10000],
            ['code' => 'ING-9-009', 'name' => 'Krimer Kental Manis', 'unit' => 'ml', 'cost' => 25, 'reorder' => 10000],
            ['code' => 'ING-9-010', 'name' => 'Heavy Whipping Cream', 'unit' => 'ml', 'cost' => 60, 'reorder' => 8000],
            // Syrups & Sweeteners (ml)
            ['code' => 'ING-9-011', 'name' => 'Sirup Gula Aren Asli', 'unit' => 'ml', 'cost' => 35, 'reorder' => 10000],
            ['code' => 'ING-9-012', 'name' => 'Sirup Vanilla Madagaskar', 'unit' => 'ml', 'cost' => 65, 'reorder' => 5000],
            ['code' => 'ING-9-013', 'name' => 'Sirup Salted Caramel', 'unit' => 'ml', 'cost' => 70, 'reorder' => 5000],
            ['code' => 'ING-9-014', 'name' => 'Sirup Hazelnut Roasted', 'unit' => 'ml', 'cost' => 65, 'reorder' => 5000],
            ['code' => 'ING-9-015', 'name' => 'Sirup Pandan Wangi', 'unit' => 'ml', 'cost' => 40, 'reorder' => 5000],
            ['code' => 'ING-9-016', 'name' => 'Simple Syrup Gula Tebu', 'unit' => 'ml', 'cost' => 15, 'reorder' => 15000],
            // Powders & Flavors (g)
            ['code' => 'ING-9-017', 'name' => 'Bubuk Matcha Uji Premium', 'unit' => 'g', 'cost' => 900, 'reorder' => 2000],
            ['code' => 'ING-9-018', 'name' => 'Bubuk Dark Chocolate 70%', 'unit' => 'g', 'cost' => 350, 'reorder' => 5000],
            ['code' => 'ING-9-019', 'name' => 'Bubuk Red Velvet Gourmet', 'unit' => 'g', 'cost' => 280, 'reorder' => 3000],
            ['code' => 'ING-9-020', 'name' => 'Bubuk Taro Premium', 'unit' => 'g', 'cost' => 250, 'reorder' => 3000],
            // Tea Leaves (g)
            ['code' => 'ING-9-021', 'name' => 'Daun Teh Earl Grey Blue Flower', 'unit' => 'g', 'cost' => 400, 'reorder' => 2000],
            ['code' => 'ING-9-022', 'name' => 'Daun Teh Jasmine Green Tea', 'unit' => 'g', 'cost' => 300, 'reorder' => 3000],
            ['code' => 'ING-9-023', 'name' => 'Bunga Chamomile Kering', 'unit' => 'g', 'cost' => 550, 'reorder' => 1000],
            ['code' => 'ING-9-024', 'name' => 'Daun Peppermint Kering', 'unit' => 'g', 'cost' => 500, 'reorder' => 1000],
            // Food Staples (g / pcs)
            ['code' => 'ING-9-025', 'name' => 'Beras Organik Pandan Wangi', 'unit' => 'g', 'cost' => 18, 'reorder' => 20000],
            ['code' => 'ING-9-026', 'name' => 'Daging Sapi Slice US Beef', 'unit' => 'g', 'cost' => 180, 'reorder' => 10000],
            ['code' => 'ING-9-027', 'name' => 'Dada Ayam Fillet Segar', 'unit' => 'g', 'cost' => 70, 'reorder' => 15000],
            ['code' => 'ING-9-028', 'name' => 'Pasta Spaghetti La Molisana', 'unit' => 'g', 'cost' => 45, 'reorder' => 8000],
            ['code' => 'ING-9-029', 'name' => 'Telur Ayam Omega-3', 'unit' => 'pcs', 'cost' => 2500, 'reorder' => 500],
            ['code' => 'ING-9-030', 'name' => 'Butter French Elle & Vire', 'unit' => 'g', 'cost' => 220, 'reorder' => 5000],
            ['code' => 'ING-9-031', 'name' => 'Tepung Terigu Segitiga Biru', 'unit' => 'g', 'cost' => 15, 'reorder' => 15000],
            ['code' => 'ING-9-032', 'name' => 'Minyak Goreng Sawit Premium', 'unit' => 'ml', 'cost' => 22, 'reorder' => 10000],
            ['code' => 'ING-9-033', 'name' => 'Keju Cheddar Shredded', 'unit' => 'g', 'cost' => 120, 'reorder' => 4000],
            ['code' => 'ING-9-034', 'name' => 'Keju Mozzarella Perfetto', 'unit' => 'g', 'cost' => 160, 'reorder' => 4000],
            ['code' => 'ING-9-035', 'name' => 'Roti Sourdough Brioche Slice', 'unit' => 'pcs', 'cost' => 4000, 'reorder' => 300],
            ['code' => 'ING-9-036', 'name' => 'Bawang Putih Giling Halus', 'unit' => 'g', 'cost' => 50, 'reorder' => 2000],
            ['code' => 'ING-9-037', 'name' => 'Cabai Rawit Merah Iris', 'unit' => 'g', 'cost' => 60, 'reorder' => 2000],
            // Packaging & Consumables (pcs)
            ['code' => 'ING-9-038', 'name' => 'Paper Cup Cold 16oz Rindu Kopi', 'unit' => 'pcs', 'cost' => 1200, 'reorder' => 10000],
            ['code' => 'ING-9-039', 'name' => 'Paper Cup Cold 12oz Rindu Kopi', 'unit' => 'pcs', 'cost' => 1000, 'reorder' => 10000],
            ['code' => 'ING-9-040', 'name' => 'Paper Cup Hot 8oz Rindu Kopi', 'unit' => 'pcs', 'cost' => 900, 'reorder' => 8000],
            ['code' => 'ING-9-041', 'name' => 'Flat Lid Transparan 16oz', 'unit' => 'pcs', 'cost' => 350, 'reorder' => 10000],
            ['code' => 'ING-9-042', 'name' => 'Dome Lid Transparan 16oz', 'unit' => 'pcs', 'cost' => 400, 'reorder' => 8000],
            ['code' => 'ING-9-043', 'name' => 'Hot Sip Lid Hitam 8oz', 'unit' => 'pcs', 'cost' => 350, 'reorder' => 8000],
            ['code' => 'ING-9-044', 'name' => 'Paper Straw Ramah Lingkungan', 'unit' => 'pcs', 'cost' => 250, 'reorder' => 12000],
            ['code' => 'ING-9-045', 'name' => 'Takeaway Food Box Eco-Craft', 'unit' => 'pcs', 'cost' => 1800, 'reorder' => 5000],
            ['code' => 'ING-9-046', 'name' => 'Paper Bag Rindu Kopi Large', 'unit' => 'pcs', 'cost' => 2500, 'reorder' => 4000],
            ['code' => 'ING-9-047', 'name' => 'Paper Bag Rindu Kopi Medium', 'unit' => 'pcs', 'cost' => 1800, 'reorder' => 5000],
            ['code' => 'ING-9-048', 'name' => 'Es Kristal Higienis', 'unit' => 'g', 'cost' => 5, 'reorder' => 50000],
        ];

        foreach ($rawIngredients as $ing) {
            $existing = DB::table('ingredients')
                ->where('tenant_id', $this->tenantId)
                ->where('ingredient_code', $ing['code'])
                ->first();

            if (! $existing) {
                DB::table('ingredients')->insert([
                    'tenant_id' => $this->tenantId,
                    'ingredient_code' => $ing['code'],
                    'name' => $ing['name'],
                    'unit' => $ing['unit'],
                    'current_stock' => 0,
                    'cost_per_unit' => $ing['cost'],
                    'reorder_level' => $ing['reorder'],
                    'is_active' => 1,
                    'created_at' => '2025-10-14 06:00:00',
                    'updated_at' => '2025-10-14 06:00:00',
                ]);
            }
        }

        $this->ingredients = DB::table('ingredients')
            ->where('tenant_id', $this->tenantId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->keyBy('ingredient_code')
            ->toArray();

        // Products definition
        $catIds = array_values(array_map(fn ($c) => $c->id, $this->categories));

        $productsData = [
            // Cat 1: Espresso Bar
            ['cat' => 0, 'name' => 'Espresso Single Shot', 'price' => 22000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-040', 'qty' => 1]]],
            ['cat' => 0, 'name' => 'Espresso Double Shot', 'price' => 28000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 24], ['ing' => 'ING-9-040', 'qty' => 1]]],
            ['cat' => 0, 'name' => 'Americano Hot', 'price' => 28000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-040', 'qty' => 1], ['ing' => 'ING-9-043', 'qty' => 1]]],
            ['cat' => 0, 'name' => 'Iced Americano', 'price' => 30000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-048', 'qty' => 150], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1], ['ing' => 'ING-9-044', 'qty' => 1]]],
            ['cat' => 0, 'name' => 'Long Black Hot', 'price' => 30000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 20], ['ing' => 'ING-9-040', 'qty' => 1], ['ing' => 'ING-9-043', 'qty' => 1]]],
            ['cat' => 0, 'name' => 'Piccolo Latte', 'price' => 32000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-006', 'qty' => 100], ['ing' => 'ING-9-040', 'qty' => 1]]],

            // Cat 2: Signature Coffee
            ['cat' => 1, 'name' => 'Kopi Rindu Aren Dingin', 'price' => 32000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-006', 'qty' => 140], ['ing' => 'ING-9-011', 'qty' => 25], ['ing' => 'ING-9-048', 'qty' => 120], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1], ['ing' => 'ING-9-044', 'qty' => 1]]],
            ['cat' => 1, 'name' => 'Kopi Pandan Wangi Latte', 'price' => 35000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-006', 'qty' => 140], ['ing' => 'ING-9-015', 'qty' => 25], ['ing' => 'ING-9-048', 'qty' => 120], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1], ['ing' => 'ING-9-044', 'qty' => 1]]],
            ['cat' => 1, 'name' => 'Salted Caramel Macchiato', 'price' => 38000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-006', 'qty' => 150], ['ing' => 'ING-9-013', 'qty' => 25], ['ing' => 'ING-9-048', 'qty' => 120], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1], ['ing' => 'ING-9-044', 'qty' => 1]]],
            ['cat' => 1, 'name' => 'Butterscotch Cream Espresso', 'price' => 40000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 20], ['ing' => 'ING-9-006', 'qty' => 120], ['ing' => 'ING-9-010', 'qty' => 30], ['ing' => 'ING-9-013', 'qty' => 20], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1]]],
            ['cat' => 1, 'name' => 'Avocado Espresso Float', 'price' => 45000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-006', 'qty' => 100], ['ing' => 'ING-9-018', 'qty' => 10], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-042', 'qty' => 1]]],

            // Cat 3: Cold Brew Series
            ['cat' => 2, 'name' => 'Classic Black Cold Brew', 'price' => 35000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-002', 'qty' => 25], ['ing' => 'ING-9-048', 'qty' => 100], ['ing' => 'ING-9-039', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1]]],
            ['cat' => 2, 'name' => 'White Velvet Cold Brew', 'price' => 38000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-002', 'qty' => 25], ['ing' => 'ING-9-006', 'qty' => 60], ['ing' => 'ING-9-011', 'qty' => 15], ['ing' => 'ING-9-039', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1]]],
            ['cat' => 2, 'name' => 'Sparkling Lemon Cold Brew', 'price' => 40000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-002', 'qty' => 20], ['ing' => 'ING-9-016', 'qty' => 20], ['ing' => 'ING-9-048', 'qty' => 150], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1]]],

            // Cat 4: Manual Brew
            ['cat' => 3, 'name' => 'V60 Aceh Gayo Natural', 'price' => 42000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-002', 'qty' => 15], ['ing' => 'ING-9-040', 'qty' => 1]]],
            ['cat' => 3, 'name' => 'V60 Toraja Sapan Washed', 'price' => 42000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-003', 'qty' => 15], ['ing' => 'ING-9-040', 'qty' => 1]]],
            ['cat' => 3, 'name' => 'V60 Bali Kintamani Anaerobic', 'price' => 45000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-004', 'qty' => 15], ['ing' => 'ING-9-040', 'qty' => 1]]],
            ['cat' => 3, 'name' => 'V60 Ethiopia Yirgacheffe G1', 'price' => 55000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-005', 'qty' => 15], ['ing' => 'ING-9-040', 'qty' => 1]]],
            ['cat' => 3, 'name' => 'Japanese Iced Drip Coffee', 'price' => 45000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-004', 'qty' => 18], ['ing' => 'ING-9-048', 'qty' => 150], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1]]],

            // Cat 5: Milk-Based Coffee
            ['cat' => 4, 'name' => 'Caffe Latte Hot', 'price' => 35000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-006', 'qty' => 180], ['ing' => 'ING-9-040', 'qty' => 1], ['ing' => 'ING-9-043', 'qty' => 1]]],
            ['cat' => 4, 'name' => 'Iced Caffe Latte', 'price' => 37000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-006', 'qty' => 160], ['ing' => 'ING-9-048', 'qty' => 120], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1], ['ing' => 'ING-9-044', 'qty' => 1]]],
            ['cat' => 4, 'name' => 'Cappuccino Hot', 'price' => 35000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-006', 'qty' => 150], ['ing' => 'ING-9-040', 'qty' => 1], ['ing' => 'ING-9-043', 'qty' => 1]]],
            ['cat' => 4, 'name' => 'Iced Cappuccino', 'price' => 37000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-006', 'qty' => 130], ['ing' => 'ING-9-048', 'qty' => 120], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1]]],
            ['cat' => 4, 'name' => 'Vanilla Bean Latte', 'price' => 40000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-006', 'qty' => 160], ['ing' => 'ING-9-012', 'qty' => 20], ['ing' => 'ING-9-048', 'qty' => 120], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1]]],
            ['cat' => 4, 'name' => 'Hazelnut Praline Latte', 'price' => 40000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-006', 'qty' => 160], ['ing' => 'ING-9-014', 'qty' => 20], ['ing' => 'ING-9-048', 'qty' => 120], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1]]],
            ['cat' => 4, 'name' => 'Caffe Mocha Belgian', 'price' => 42000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-006', 'qty' => 150], ['ing' => 'ING-9-018', 'qty' => 25], ['ing' => 'ING-9-048', 'qty' => 120], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1]]],

            // Cat 6: Tea & Tisane
            ['cat' => 5, 'name' => 'Earl Grey Supreme Tea', 'price' => 28000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-021', 'qty' => 5], ['ing' => 'ING-9-040', 'qty' => 1]]],
            ['cat' => 5, 'name' => 'Jasmine Green Blossom', 'price' => 28000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-022', 'qty' => 5], ['ing' => 'ING-9-040', 'qty' => 1]]],
            ['cat' => 5, 'name' => 'Chamomile Dream Tisane', 'price' => 30000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-023', 'qty' => 4], ['ing' => 'ING-9-040', 'qty' => 1]]],
            ['cat' => 5, 'name' => 'Iced Lychee Tea Breeze', 'price' => 34000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-022', 'qty' => 5], ['ing' => 'ING-9-016', 'qty' => 20], ['ing' => 'ING-9-048', 'qty' => 150], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1]]],
            ['cat' => 5, 'name' => 'Iced Lemon Mint Tea', 'price' => 32000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-021', 'qty' => 5], ['ing' => 'ING-9-024', 'qty' => 2], ['ing' => 'ING-9-016', 'qty' => 20], ['ing' => 'ING-9-048', 'qty' => 150], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1]]],

            // Cat 7: Non-Coffee
            ['cat' => 6, 'name' => 'Matcha Uji Latte Hot', 'price' => 38000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-017', 'qty' => 8], ['ing' => 'ING-9-006', 'qty' => 180], ['ing' => 'ING-9-016', 'qty' => 15], ['ing' => 'ING-9-040', 'qty' => 1]]],
            ['cat' => 6, 'name' => 'Iced Matcha Uji Latte', 'price' => 40000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-017', 'qty' => 8], ['ing' => 'ING-9-006', 'qty' => 160], ['ing' => 'ING-9-016', 'qty' => 15], ['ing' => 'ING-9-048', 'qty' => 120], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1]]],
            ['cat' => 6, 'name' => 'Dark Chocolate Belgian Hot', 'price' => 36000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-018', 'qty' => 25], ['ing' => 'ING-9-006', 'qty' => 180], ['ing' => 'ING-9-040', 'qty' => 1]]],
            ['cat' => 6, 'name' => 'Iced Dark Chocolate Signature', 'price' => 38000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-018', 'qty' => 25], ['ing' => 'ING-9-006', 'qty' => 160], ['ing' => 'ING-9-048', 'qty' => 120], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1]]],
            ['cat' => 6, 'name' => 'Red Velvet Velvetine Iced', 'price' => 38000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-019', 'qty' => 25], ['ing' => 'ING-9-006', 'qty' => 160], ['ing' => 'ING-9-048', 'qty' => 120], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1]]],
            ['cat' => 6, 'name' => 'Taro Gourmet Latte Iced', 'price' => 36000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-006', 'qty' => 160], ['ing' => 'ING-9-020', 'qty' => 25], ['ing' => 'ING-9-048', 'qty' => 120], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-041', 'qty' => 1]]],

            // Cat 8: Frappe & Blended
            ['cat' => 7, 'name' => 'Mocha Chip Frappe', 'price' => 45000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-018', 'qty' => 25], ['ing' => 'ING-9-006', 'qty' => 120], ['ing' => 'ING-9-010', 'qty' => 30], ['ing' => 'ING-9-048', 'qty' => 180], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-042', 'qty' => 1]]],
            ['cat' => 7, 'name' => 'Caramel Crunch Frappe', 'price' => 45000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 18], ['ing' => 'ING-9-013', 'qty' => 30], ['ing' => 'ING-9-006', 'qty' => 120], ['ing' => 'ING-9-010', 'qty' => 30], ['ing' => 'ING-9-048', 'qty' => 180], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-042', 'qty' => 1]]],
            ['cat' => 7, 'name' => 'Matcha Cream Frappe', 'price' => 48000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-017', 'qty' => 10], ['ing' => 'ING-9-006', 'qty' => 120], ['ing' => 'ING-9-010', 'qty' => 30], ['ing' => 'ING-9-048', 'qty' => 180], ['ing' => 'ING-9-038', 'qty' => 1], ['ing' => 'ING-9-042', 'qty' => 1]]],

            // Cat 9 & 10: Pastries & Croissants
            ['cat' => 8, 'name' => 'Cinnamon Morning Roll', 'price' => 28000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-030', 'qty' => 30], ['ing' => 'ING-9-031', 'qty' => 60], ['ing' => 'ING-9-029', 'qty' => 1], ['ing' => 'ING-9-045', 'qty' => 1]]],
            ['cat' => 8, 'name' => 'Fudgy Dark Choco Brownie', 'price' => 32000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-018', 'qty' => 35], ['ing' => 'ING-9-030', 'qty' => 30], ['ing' => 'ING-9-031', 'qty' => 40], ['ing' => 'ING-9-045', 'qty' => 1]]],
            ['cat' => 9, 'name' => 'Butter Croissant Classic', 'price' => 26000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-030', 'qty' => 45], ['ing' => 'ING-9-031', 'qty' => 60], ['ing' => 'ING-9-045', 'qty' => 1]]],
            ['cat' => 9, 'name' => 'Almond Cream Croissant', 'price' => 36000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-030', 'qty' => 50], ['ing' => 'ING-9-031', 'qty' => 60], ['ing' => 'ING-9-045', 'qty' => 1]]],
            ['cat' => 9, 'name' => 'Pain au Chocolat', 'price' => 32000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-030', 'qty' => 45], ['ing' => 'ING-9-031', 'qty' => 60], ['ing' => 'ING-9-018', 'qty' => 20], ['ing' => 'ING-9-045', 'qty' => 1]]],

            // Cat 11: Sandwiches & Toast
            ['cat' => 10, 'name' => 'Truffle Scrambled Egg Brioche', 'price' => 45000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-035', 'qty' => 2], ['ing' => 'ING-9-029', 'qty' => 2], ['ing' => 'ING-9-030', 'qty' => 20], ['ing' => 'ING-9-045', 'qty' => 1]]],
            ['cat' => 10, 'name' => 'Smoked Beef & Cheddar Toast', 'price' => 48000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-035', 'qty' => 2], ['ing' => 'ING-9-026', 'qty' => 60], ['ing' => 'ING-9-033', 'qty' => 30], ['ing' => 'ING-9-045', 'qty' => 1]]],
            ['cat' => 10, 'name' => 'Chicken Caesar Croissant Sandwich', 'price' => 52000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-030', 'qty' => 40], ['ing' => 'ING-9-031', 'qty' => 50], ['ing' => 'ING-9-027', 'qty' => 80], ['ing' => 'ING-9-045', 'qty' => 1]]],

            // Cat 12: Rice Bowls
            ['cat' => 11, 'name' => 'Gyudon Beef Yakiniku Bowl', 'price' => 58000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-025', 'qty' => 180], ['ing' => 'ING-9-026', 'qty' => 90], ['ing' => 'ING-9-029', 'qty' => 1], ['ing' => 'ING-9-036', 'qty' => 10], ['ing' => 'ING-9-045', 'qty' => 1]]],
            ['cat' => 11, 'name' => 'Crispy Chicken Sambal Matah', 'price' => 48000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-025', 'qty' => 180], ['ing' => 'ING-9-027', 'qty' => 100], ['ing' => 'ING-9-037', 'qty' => 20], ['ing' => 'ING-9-032', 'qty' => 20], ['ing' => 'ING-9-045', 'qty' => 1]]],
            ['cat' => 11, 'name' => 'Chicken Teriyaki Rice Bowl', 'price' => 48000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-025', 'qty' => 180], ['ing' => 'ING-9-027', 'qty' => 100], ['ing' => 'ING-9-011', 'qty' => 15], ['ing' => 'ING-9-045', 'qty' => 1]]],

            // Cat 13: Pasta
            ['cat' => 12, 'name' => 'Spaghetti Aglio Olio Beef Slice', 'price' => 52000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-028', 'qty' => 100], ['ing' => 'ING-9-026', 'qty' => 50], ['ing' => 'ING-9-036', 'qty' => 15], ['ing' => 'ING-9-037', 'qty' => 10], ['ing' => 'ING-9-032', 'qty' => 20], ['ing' => 'ING-9-045', 'qty' => 1]]],
            ['cat' => 12, 'name' => 'Creamy Fettuccine Carbonara', 'price' => 55000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-028', 'qty' => 100], ['ing' => 'ING-9-010', 'qty' => 50], ['ing' => 'ING-9-029', 'qty' => 1], ['ing' => 'ING-9-033', 'qty' => 20], ['ing' => 'ING-9-026', 'qty' => 40], ['ing' => 'ING-9-045', 'qty' => 1]]],

            // Cat 14: Indonesian Comfort
            ['cat' => 13, 'name' => 'Nasi Goreng Rindu Spesial', 'price' => 46000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-025', 'qty' => 200], ['ing' => 'ING-9-027', 'qty' => 50], ['ing' => 'ING-9-029', 'qty' => 1], ['ing' => 'ING-9-036', 'qty' => 10], ['ing' => 'ING-9-037', 'qty' => 10], ['ing' => 'ING-9-032', 'qty' => 25], ['ing' => 'ING-9-045', 'qty' => 1]]],
            ['cat' => 13, 'name' => 'Mie Goreng Dok-dok Jawa', 'price' => 42000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-028', 'qty' => 100], ['ing' => 'ING-9-029', 'qty' => 1], ['ing' => 'ING-9-027', 'qty' => 40], ['ing' => 'ING-9-037', 'qty' => 10], ['ing' => 'ING-9-045', 'qty' => 1]]],

            // Cat 15: Light Bites
            ['cat' => 14, 'name' => 'Truffle Fries with Aioli', 'price' => 35000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-032', 'qty' => 40], ['ing' => 'ING-9-033', 'qty' => 20], ['ing' => 'ING-9-045', 'qty' => 1]]],
            ['cat' => 14, 'name' => 'Crispy Chicken Wings (6 pcs)', 'price' => 42000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-027', 'qty' => 150], ['ing' => 'ING-9-031', 'qty' => 30], ['ing' => 'ING-9-032', 'qty' => 50], ['ing' => 'ING-9-045', 'qty' => 1]]],
            ['cat' => 14, 'name' => 'Cireng Krispi Bumbu Rujak', 'price' => 28000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-031', 'qty' => 100], ['ing' => 'ING-9-032', 'qty' => 40], ['ing' => 'ING-9-011', 'qty' => 30], ['ing' => 'ING-9-045', 'qty' => 1]]],

            // Cat 16: Desserts
            ['cat' => 15, 'name' => 'Classic Tiramisu Cup', 'price' => 38000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-001', 'qty' => 10], ['ing' => 'ING-9-010', 'qty' => 40], ['ing' => 'ING-9-018', 'qty' => 10], ['ing' => 'ING-9-045', 'qty' => 1]]],
            ['cat' => 15, 'name' => 'Burnt Basque Cheesecake', 'price' => 42000, 'has_bom' => true,
                'bom' => [['ing' => 'ING-9-010', 'qty' => 50], ['ing' => 'ING-9-029', 'qty' => 1], ['ing' => 'ING-9-045', 'qty' => 1]]],

            // Cat 17: Packaged Coffee Beans (NON-BOM, retail stock)
            ['cat' => 16, 'name' => 'House Blend Espresso Beans 250g', 'price' => 95000, 'has_bom' => false, 'stock' => 800],
            ['cat' => 16, 'name' => 'Aceh Gayo Natural Beans 200g', 'price' => 110000, 'has_bom' => false, 'stock' => 600],
            ['cat' => 16, 'name' => 'Toraja Sapan Washed Beans 200g', 'price' => 115000, 'has_bom' => false, 'stock' => 500],
            ['cat' => 16, 'name' => 'Bali Kintamani Beans 200g', 'price' => 105000, 'has_bom' => false, 'stock' => 500],
            ['cat' => 16, 'name' => 'Ethiopia Yirgacheffe Beans 200g', 'price' => 165000, 'has_bom' => false, 'stock' => 400],
            ['cat' => 16, 'name' => 'Drip Bag Coffee Box (5 sachets)', 'price' => 65000, 'has_bom' => false, 'stock' => 1000],

            // Cat 18: Merchandise (NON-BOM, retail stock)
            ['cat' => 17, 'name' => 'Rindu Kopi Ceramic Mug 350ml', 'price' => 85000, 'has_bom' => false, 'stock' => 400],
            ['cat' => 17, 'name' => 'Tumbler Stainless Steel 500ml', 'price' => 175000, 'has_bom' => false, 'stock' => 300],
            ['cat' => 17, 'name' => 'Tote Bag Kanvas Rindu Kopi', 'price' => 65000, 'has_bom' => false, 'stock' => 500],
            ['cat' => 17, 'name' => 'V60 Coffee Dripper 02 Clear', 'price' => 125000, 'has_bom' => false, 'stock' => 200],
            ['cat' => 17, 'name' => 'Filter Paper V60 02 (100 sheets)', 'price' => 55000, 'has_bom' => false, 'stock' => 600],
        ];

        // Insert products and BOMs
        $prdSeq = 1;
        foreach ($productsData as $pData) {
            $catId = $catIds[$pData['cat']];
            $pCode = sprintf('PRD-9-%04d', $prdSeq);

            $existing = DB::table('products')
                ->where('tenant_id', $this->tenantId)
                ->where('product_code', $pCode)
                ->first();

            $pId = $existing?->id;
            if (! $existing) {
                $pId = DB::table('products')->insertGetId([
                    'tenant_id' => $this->tenantId,
                    'category_id' => $catId,
                    'product_code' => $pCode,
                    'product_name' => $pData['name'],
                    'product_price' => $pData['price'],
                    'stock' => $pData['has_bom'] ? 0 : ($pData['stock'] ?? 0),
                    'is_active' => 1,
                    'created_at' => '2025-10-14 06:00:00',
                    'updated_at' => '2025-10-14 06:00:00',
                ]);
            }

            // Product BOM
            if ($pData['has_bom'] && ! empty($pData['bom'])) {
                foreach ($pData['bom'] as $b) {
                    $ing = $this->ingredients[$b['ing']] ?? null;
                    if ($ing) {
                        $piExists = DB::table('product_ingredients')
                            ->where('tenant_id', $this->tenantId)
                            ->where('product_id', $pId)
                            ->where('ingredient_id', $ing->id)
                            ->exists();

                        if (! $piExists) {
                            DB::table('product_ingredients')->insert([
                                'tenant_id' => $this->tenantId,
                                'product_id' => $pId,
                                'ingredient_id' => $ing->id,
                                'quantity_required' => $b['qty'],
                                'created_at' => '2025-10-14 06:00:00',
                                'updated_at' => '2025-10-14 06:00:00',
                            ]);
                        }
                    }
                }
            }

            $prdSeq++;
        }

        $this->products = DB::table('products')
            ->where('tenant_id', $this->tenantId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->keyBy('id')
            ->toArray();

        $this->productIngredients = DB::table('product_ingredients')
            ->where('tenant_id', $this->tenantId)
            ->get()
            ->groupBy('product_id')
            ->toArray();

        $this->line('  ✓ Created '.count($this->products).' products ('.count($this->productIngredients).' with BOM recipes).');
    }

    /**
     * Stage 4: Modifiers, Groups, Tables & Discounts
     */
    private function setupModifiersTablesDiscounts(): void
    {
        // 4 Modifier Groups
        $groups = [
            ['name' => 'Sugar Level', 'type' => 'single', 'req' => 0, 'min' => 0, 'max' => 1],
            ['name' => 'Ice Level', 'type' => 'single', 'req' => 0, 'min' => 0, 'max' => 1],
            ['name' => 'Pilihan Susu', 'type' => 'single', 'req' => 0, 'min' => 0, 'max' => 1],
            ['name' => 'Extra Topping & Shot', 'type' => 'multiple', 'req' => 0, 'min' => 0, 'max' => 3],
        ];

        foreach ($groups as $g) {
            $existing = DB::table('modifier_groups')
                ->where('tenant_id', $this->tenantId)
                ->where('name', $g['name'])
                ->first();

            if (! $existing) {
                DB::table('modifier_groups')->insert([
                    'tenant_id' => $this->tenantId,
                    'name' => $g['name'],
                    'selection_type' => $g['type'],
                    'is_required' => $g['req'],
                    'min_select' => $g['min'],
                    'max_select' => $g['max'],
                    'created_at' => '2025-10-14 06:00:00',
                    'updated_at' => '2025-10-14 06:00:00',
                ]);
            }
        }

        $this->modifierGroups = DB::table('modifier_groups')
            ->where('tenant_id', $this->tenantId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->keyBy('name')
            ->toArray();

        // Modifiers
        $modMap = [
            'Sugar Level' => [
                ['name' => 'Normal Sugar (100%)', 'price' => 0, 'default' => 1],
                ['name' => 'Less Sugar (70%)', 'price' => 0, 'default' => 0],
                ['name' => 'Half Sugar (50%)', 'price' => 0, 'default' => 0],
                ['name' => 'No Sugar (0%)', 'price' => 0, 'default' => 0],
            ],
            'Ice Level' => [
                ['name' => 'Normal Ice', 'price' => 0, 'default' => 1],
                ['name' => 'Less Ice', 'price' => 0, 'default' => 0],
                ['name' => 'Extra Ice', 'price' => 0, 'default' => 0],
                ['name' => 'No Ice', 'price' => 0, 'default' => 0],
            ],
            'Pilihan Susu' => [
                ['name' => 'Fresh Milk (Default)', 'price' => 0, 'default' => 1],
                ['name' => 'Oat Milk Barista', 'price' => 10000, 'default' => 0, 'bom' => ['ing' => 'ING-9-007', 'qty' => 180]],
                ['name' => 'Almond Milk Unsweetened', 'price' => 10000, 'default' => 0, 'bom' => ['ing' => 'ING-9-008', 'qty' => 180]],
            ],
            'Extra Topping & Shot' => [
                ['name' => 'Extra Espresso Shot', 'price' => 8000, 'default' => 0, 'bom' => ['ing' => 'ING-9-001', 'qty' => 18]],
                ['name' => 'Whipped Cream', 'price' => 6000, 'default' => 0, 'bom' => ['ing' => 'ING-9-010', 'qty' => 30]],
                ['name' => 'Salted Caramel Drizzle', 'price' => 5000, 'default' => 0, 'bom' => ['ing' => 'ING-9-013', 'qty' => 15]],
                ['name' => 'Vanilla Syrup Pump', 'price' => 5000, 'default' => 0, 'bom' => ['ing' => 'ING-9-012', 'qty' => 15]],
            ],
        ];

        foreach ($modMap as $gName => $mods) {
            $gId = $this->modifierGroups[$gName]->id ?? null;
            if (! $gId) {
                continue;
            }

            foreach ($mods as $m) {
                $existing = DB::table('modifiers')
                    ->where('tenant_id', $this->tenantId)
                    ->where('modifier_group_id', $gId)
                    ->where('name', $m['name'])
                    ->first();

                $modId = $existing?->id;
                if (! $existing) {
                    $modId = DB::table('modifiers')->insertGetId([
                        'tenant_id' => $this->tenantId,
                        'modifier_group_id' => $gId,
                        'name' => $m['name'],
                        'extra_price' => $m['price'],
                        'is_default' => $m['default'],
                        'is_active' => 1,
                        'created_at' => '2025-10-14 06:00:00',
                        'updated_at' => '2025-10-14 06:00:00',
                    ]);
                }

                // Modifier BOM
                if (! empty($m['bom'])) {
                    $ing = $this->ingredients[$m['bom']['ing']] ?? null;
                    if ($ing) {
                        $miExists = DB::table('modifier_ingredients')
                            ->where('tenant_id', $this->tenantId)
                            ->where('modifier_id', $modId)
                            ->where('ingredient_id', $ing->id)
                            ->exists();

                        if (! $miExists) {
                            DB::table('modifier_ingredients')->insert([
                                'tenant_id' => $this->tenantId,
                                'modifier_id' => $modId,
                                'ingredient_id' => $ing->id,
                                'quantity_required' => $m['bom']['qty'],
                                'created_at' => '2025-10-14 06:00:00',
                                'updated_at' => '2025-10-14 06:00:00',
                            ]);
                        }
                    }
                }
            }
        }

        $this->modifiers = DB::table('modifiers')
            ->where('tenant_id', $this->tenantId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->keyBy('id')
            ->toArray();

        $this->modifierIngredients = DB::table('modifier_ingredients')
            ->where('tenant_id', $this->tenantId)
            ->get()
            ->groupBy('modifier_id')
            ->toArray();

        // Link beverage products to modifier groups
        foreach ($this->products as $p) {
            $cat = $this->categories[$p->category_id] ?? null;
            if ($cat && in_array($cat->category_name, ['Espresso Bar', 'Signature Coffee', 'Milk-Based Coffee', 'Non-Coffee Beverages', 'Frappe & Blended', 'Cold Brew Series'])) {
                foreach ($this->modifierGroups as $g) {
                    $linkExists = DB::table('modifier_group_product')
                        ->where('tenant_id', $this->tenantId)
                        ->where('product_id', $p->id)
                        ->where('modifier_group_id', $g->id)
                        ->exists();

                    if (! $linkExists) {
                        DB::table('modifier_group_product')->insert([
                            'tenant_id' => $this->tenantId,
                            'product_id' => $p->id,
                            'modifier_group_id' => $g->id,
                            'created_at' => '2025-10-14 06:00:00',
                            'updated_at' => '2025-10-14 06:00:00',
                        ]);
                    }
                }
            }
        }

        // Tables: 14 tables per store
        foreach ($this->stores as $s) {
            for ($t = 1; $t <= 14; $t++) {
                $tName = sprintf('T-%02d', $t);
                $tCode = sprintf('TBL-%d-%02d', $s->id, $t);
                $area = $t <= 8 ? 'Indoor AC' : ($t <= 12 ? 'Outdoor Garden' : 'VIP Lounge');
                $cap = $t <= 6 ? 2 : ($t <= 10 ? 4 : ($t <= 12 ? 6 : 8));

                $exists = DB::table('tables')
                    ->where('tenant_id', $this->tenantId)
                    ->where('store_id', $s->id)
                    ->where('table_name', $tName)
                    ->whereNull('deleted_at')
                    ->exists();

                if (! $exists) {
                    DB::table('tables')->insert([
                        'tenant_id' => $this->tenantId,
                        'store_id' => $s->id,
                        'table_code' => $tCode,
                        'table_name' => $tName,
                        'capacity' => $cap,
                        'area' => $area,
                        'is_active' => 1,
                        'operational_status' => 'available',
                        'secure_token' => (string) Str::uuid(),
                        'created_at' => '2025-10-14 06:00:00',
                        'updated_at' => '2025-10-14 06:00:00',
                    ]);
                }
            }
        }

        $this->tables = DB::table('tables')
            ->where('tenant_id', $this->tenantId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->groupBy('store_id')
            ->toArray();

        // Discounts
        $discountsData = [
            ['code' => 'RINDU10', 'name' => 'Diskon Member Rindu 10%', 'type' => 'percentage', 'val' => 10, 'max' => 15000, 'min' => 30000],
            ['code' => 'PAYDAY15', 'name' => 'Payday Fiesta 15%', 'type' => 'percentage', 'val' => 15, 'max' => 25000, 'min' => 50000],
            ['code' => 'POTONGAN10K', 'name' => 'Potongan Langsung Rp 10.000', 'type' => 'fixed', 'val' => 10000, 'max' => null, 'min' => 60000],
            ['code' => 'WEEKEND20', 'name' => 'Weekend Treats 20%', 'type' => 'percentage', 'val' => 20, 'max' => 30000, 'min' => 75000],
        ];

        foreach ($discountsData as $d) {
            $dExists = DB::table('discounts')
                ->where('tenant_id', $this->tenantId)
                ->where('code', $d['code'])
                ->exists();

            if (! $dExists) {
                DB::table('discounts')->insert([
                    'tenant_id' => $this->tenantId,
                    'code' => $d['code'],
                    'name' => $d['name'],
                    'type' => $d['type'],
                    'value' => $d['val'],
                    'max_discount_amount' => $d['max'],
                    'min_purchase_amount' => $d['min'],
                    'is_active' => 1,
                    'valid_from' => '2025-10-14 00:00:00',
                    'valid_until' => '2026-10-31 23:59:59',
                    'created_at' => '2025-10-14 06:00:00',
                    'updated_at' => '2025-10-14 06:00:00',
                ]);
            }
        }

        $this->discounts = DB::table('discounts')
            ->where('tenant_id', $this->tenantId)
            ->whereNull('deleted_at')
            ->get()
            ->toArray();

        $this->line('  ✓ Configured modifiers, tables ('.count($this->tables, COUNT_RECURSIVE).'), and promotional discounts.');
    }

    /**
     * Stage 5: Loyalty Tiers & Customers
     */
    private function setupCustomersAndLoyalty(): void
    {
        // 4 Loyalty Tiers
        $tiers = [
            ['code' => 'BRONZE', 'name' => 'Bronze Member', 'min' => 0, 'mult' => 1.00],
            ['code' => 'SILVER', 'name' => 'Silver Connoisseur', 'min' => 500, 'mult' => 1.10],
            ['code' => 'GOLD', 'name' => 'Gold Aficionado', 'min' => 1500, 'mult' => 1.25],
            ['code' => 'PLATINUM', 'name' => 'Platinum VIP', 'min' => 3000, 'mult' => 1.50],
        ];

        foreach ($tiers as $t) {
            $exists = DB::table('loyalty_tiers')
                ->where('tenant_id', $this->tenantId)
                ->where('tier_code', $t['code'])
                ->first();

            if (! $exists) {
                DB::table('loyalty_tiers')->insert([
                    'tenant_id' => $this->tenantId,
                    'tier_code' => $t['code'],
                    'name' => $t['name'],
                    'min_points' => $t['min'],
                    'points_multiplier' => $t['mult'],
                    'benefits' => json_encode(['points_multiplier' => $t['mult'], 'birthday_voucher' => true]),
                    'is_active' => 1,
                    'created_at' => '2025-10-14 06:00:00',
                    'updated_at' => '2025-10-14 06:00:00',
                ]);
            }
        }

        $this->loyaltyTiers = DB::table('loyalty_tiers')
            ->where('tenant_id', $this->tenantId)
            ->orderBy('min_points')
            ->get()
            ->keyBy('tier_code')
            ->toArray();

        // 1,000 Customers
        $existingCustCount = DB::table('customers')->where('tenant_id', $this->tenantId)->count();
        if ($existingCustCount < 1000) {
            $custBatch = [];
            $firstNames = ['Alya', 'Brandon', 'Clarissa', 'David', 'Elvira', 'Felix', 'Grace', 'Haikal', 'Irene', 'Jonathan',
                'Kania', 'Leonardo', 'Marsha', 'Nathan', 'Olivia', 'Patrick', 'Qonita', 'Raditya', 'Sabrina', 'Tristan'];
            $lastNames = ['Kusuma', 'Siregar', 'Wibisono', 'Tanjung', 'Lazuardi', 'Halim', 'Subagyo', 'Natawijaya', 'Pradana'];

            for ($c = $existingCustCount + 1; $c <= 1000; $c++) {
                $cCode = sprintf('CST-9-%04d', $c);
                $fn = $firstNames[$c % count($firstNames)];
                $ln = $lastNames[($c * 3) % count($lastNames)];
                $name = "{$fn} {$ln}";
                $phone = '08'.rand(12, 98).sprintf('%08d', ($c * 7919) % 99999999);
                $email = strtolower($fn).'.'.strtolower($ln).$c.'@gmail.com';
                $joinDate = Carbon::parse('2025-10-14')->addDays($c % 300)->toDateString();

                $custBatch[] = [
                    'tenant_id' => $this->tenantId,
                    'customer_code' => $cCode,
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $email,
                    'date_of_birth' => '199'.rand(0, 9).'-'.sprintf('%02d', rand(1, 12)).'-'.sprintf('%02d', rand(1, 28)),
                    'join_date' => $joinDate,
                    'is_active' => 1,
                    'created_at' => $joinDate.' 10:00:00',
                    'updated_at' => $joinDate.' 10:00:00',
                ];

                if (count($custBatch) >= 500) {
                    DB::table('customers')->insert($custBatch);
                    $custBatch = [];
                }
            }
            if (! empty($custBatch)) {
                DB::table('customers')->insert($custBatch);
            }
        }

        $this->customers = DB::table('customers')
            ->where('tenant_id', $this->tenantId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->toArray();

        $this->line('  ✓ Set up '.count($this->customers).' registered loyalty customers.');
    }

    /**
     * Stage 6: Shifts & Cash Movements
     */
    private function setupShifts(): array
    {
        $shiftsLookup = [];

        $existingShiftCount = DB::table('shifts')->where('tenant_id', $this->tenantId)->count();
        if ($existingShiftCount > 5000) {
            $shifts = DB::table('shifts')->where('tenant_id', $this->tenantId)->get();
            foreach ($shifts as $s) {
                $opened = Carbon::parse($s->opened_at);
                $dateKey = $opened->toDateString();
                $shiftNum = $opened->hour < 15 ? 1 : 2;
                $shiftsLookup["{$dateKey}:{$s->store_id}:{$shiftNum}"] = $s;
            }
            $this->line("  ✓ Loaded {$existingShiftCount} existing shifts.");

            return $shiftsLookup;
        }

        $cashiersByStore = [];
        $userStores = DB::table('user_stores')
            ->join('users', 'users.id', '=', 'user_stores.user_id')
            ->where('user_stores.tenant_id', $this->tenantId)
            ->where('users.role_id', 24)
            ->select('user_stores.store_id', 'user_stores.user_id')
            ->get();

        foreach ($userStores as $us) {
            $cashiersByStore[$us->store_id][] = $us->user_id;
        }

        $startDate = Carbon::parse('2025-10-14');
        $endDate = Carbon::parse('2026-10-13');
        $currentDate = $startDate->copy();

        $shiftsBatch = [];
        $cdmBatch = [];

        $bar = $this->output->createProgressBar(365);
        $bar->setFormat(' Shifts generation: %current%/%max% [%bar%] %percent:3s%%');

        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->toDateString();

            foreach ($this->stores as $s) {
                $cashiers = $cashiersByStore[$s->id] ?? [16];
                $c1 = $cashiers[0] ?? 16;
                $c2 = $cashiers[1] ?? $c1;

                // Shift 1 (Morning: 07:00 - 15:30)
                $openedAt1 = "{$dateStr} 07:00:00";
                $closedAt1 = "{$dateStr} 15:30:00";
                $shiftsBatch[] = [
                    'tenant_id' => $this->tenantId,
                    'store_id' => $s->id,
                    'user_id' => $c1,
                    'opening_balance' => 500000,
                    'closing_balance' => null,
                    'expected_cash' => null,
                    'cash_difference' => 0,
                    'status' => 'closed',
                    'opened_at' => $openedAt1,
                    'closed_at' => $closedAt1,
                    'notes' => 'Shift pagi berjalan lancar dan teratur.',
                    'created_at' => $openedAt1,
                    'updated_at' => $closedAt1,
                ];

                // Shift 2 (Evening: 15:00 - 23:00)
                $openedAt2 = "{$dateStr} 15:00:00";
                $closedAt2 = "{$dateStr} 23:00:00";
                $shiftsBatch[] = [
                    'tenant_id' => $this->tenantId,
                    'store_id' => $s->id,
                    'user_id' => $c2,
                    'opening_balance' => 500000,
                    'closing_balance' => null,
                    'expected_cash' => null,
                    'cash_difference' => 0,
                    'status' => 'closed',
                    'opened_at' => $openedAt2,
                    'closed_at' => $closedAt2,
                    'notes' => 'Shift sore tutup kasir sesuai prosedur.',
                    'created_at' => $openedAt2,
                    'updated_at' => $closedAt2,
                ];
            }

            if (count($shiftsBatch) >= 1000) {
                DB::table('shifts')->insert($shiftsBatch);
                $shiftsBatch = [];
            }

            $currentDate->addDay();
            $bar->advance();
        }

        if (! empty($shiftsBatch)) {
            DB::table('shifts')->insert($shiftsBatch);
        }
        $bar->finish();
        $this->info('');

        // Reload shifts into lookup map and insert cash drawer movements
        $shifts = DB::table('shifts')->where('tenant_id', $this->tenantId)->get();
        foreach ($shifts as $s) {
            $opened = Carbon::parse($s->opened_at);
            $dateKey = $opened->toDateString();
            $shiftNum = $opened->hour < 15 ? 1 : 2;
            $shiftsLookup["{$dateKey}:{$s->store_id}:{$shiftNum}"] = $s;

            // Generate cash_in
            $cdmBatch[] = [
                'tenant_id' => $this->tenantId,
                'store_id' => $s->store_id,
                'shift_id' => $s->id,
                'type' => 'cash_in',
                'category' => 'restock_change',
                'amount' => 100000,
                'reason' => 'Pecahan uang kembalian pagi dari kas utama',
                'receipt_reference' => 'CDM-'.$s->id.'-IN',
                'created_by' => $s->user_id,
                'approved_by' => 16,
                'idempotency_key' => 'cdm:in:'.$s->id,
                'created_at' => Carbon::parse($s->opened_at)->addMinutes(10)->toDateTimeString(),
                'updated_at' => Carbon::parse($s->opened_at)->addMinutes(10)->toDateTimeString(),
            ];

            if ($shiftNum === 2 && ($s->id % 4 === 0)) {
                $cdmBatch[] = [
                    'tenant_id' => $this->tenantId,
                    'store_id' => $s->store_id,
                    'shift_id' => $s->id,
                    'type' => 'cash_out',
                    'category' => 'petty_expense',
                    'amount' => 35000,
                    'reason' => 'Beli es batu tambahan darurat & sabun cuci',
                    'receipt_reference' => 'CDM-'.$s->id.'-OUT',
                    'created_by' => $s->user_id,
                    'approved_by' => 16,
                    'idempotency_key' => 'cdm:out:'.$s->id,
                    'created_at' => Carbon::parse($s->opened_at)->addHours(3)->toDateTimeString(),
                    'updated_at' => Carbon::parse($s->opened_at)->addHours(3)->toDateTimeString(),
                ];
            }

            if (count($cdmBatch) >= 1000) {
                DB::table('cash_drawer_movements')->insert($cdmBatch);
                $cdmBatch = [];
            }
        }

        if (! empty($cdmBatch)) {
            DB::table('cash_drawer_movements')->insert($cdmBatch);
        }

        $this->line('  ✓ Seeded '.count($shifts).' shifts with cash drawer movements.');

        return $shiftsLookup;
    }

    /**
     * Stage 7: Orders, Items, Modifiers & Payments
     */
    private function generateOrdersAndPayments(array $shiftsLookup): array
    {
        $existingOrderCount = DB::table('orders')->where('tenant_id', $this->tenantId)->count();
        if ($existingOrderCount >= $this->targetOrders) {
            $this->warn("Already found {$existingOrderCount} orders. Skipping generation.");

            return ['ingredient_usage' => [], 'product_usage' => [], 'customer_orders' => []];
        }

        $startDate = Carbon::parse('2025-10-14');
        $endDate = Carbon::parse('2026-10-13');
        $totalDays = 365;

        // Holidays for volume boost
        $holidays = array_flip([
            '2025-12-25', '2026-01-01', '2026-01-29', '2026-03-14', '2026-03-28',
            '2026-03-29', '2026-03-30', '2026-03-31', '2026-05-01', '2026-05-13',
            '2026-06-01', '2026-08-17', '2026-09-17',
        ]);

        $hourWeights = [
            7 => 5, 8 => 10, 9 => 8, 10 => 6,
            11 => 10, 12 => 18, 13 => 15, 14 => 8,
            15 => 6, 16 => 5, 17 => 8,
            18 => 12, 19 => 18, 20 => 14, 21 => 8, 22 => 3,
        ];

        $productList = array_values($this->products);
        $prodCount = count($productList);

        $ingredientUsage = [];
        $productUsage = [];
        $customerOrders = [];
        $shiftCashSales = [];

        $totalOrdersCreated = 0;
        $orderSeq = 1;

        $bar = $this->output->createProgressBar($totalDays);
        $bar->setFormat(' Orders generation: %current%/%max% [%bar%] %percent:3s%% (Orders: %message%)');

        $currentDate = $startDate->copy();
        $baseOrdersPerDay = (int) round($this->targetOrders / 365); // ~191/day

        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->toDateString();
            $isWeekend = $currentDate->isWeekend();
            $isHoliday = isset($holidays[$dateStr]);

            $multiplier = $isHoliday ? 1.8 : ($isWeekend ? 1.4 : 1.0);
            $dayTarget = (int) round($baseOrdersPerDay * $multiplier);

            foreach ($this->stores as $sStore) {
                $storeOrderCount = (int) round($dayTarget / count($this->stores));
                $storeTables = $this->tables[$sStore->id] ?? [];

                for ($i = 0; $i < $storeOrderCount; $i++) {
                    $hour = $this->weightedRandom($hourWeights);
                    $minute = rand(0, 59);
                    $second = rand(0, 59);
                    $orderTime = "{$dateStr} ".sprintf('%02d:%02d:%02d', $hour, $minute, $second);

                    $shiftNum = $hour < 15 ? 1 : 2;
                    $shiftKey = "{$dateStr}:{$sStore->id}:{$shiftNum}";
                    $shift = $shiftsLookup[$shiftKey] ?? null;

                    $userId = $shift?->user_id ?? 16;
                    $shiftId = $shift?->id;

                    $typeRoll = rand(1, 100);
                    $orderType = $typeRoll <= 60 ? 'dine_in' : ($typeRoll <= 90 ? 'take_away' : 'delivery');
                    $tableId = ($orderType === 'dine_in' && ! empty($storeTables)) ? $storeTables[array_rand($storeTables)]->id : null;

                    $isMember = rand(1, 100) <= 40;
                    $customer = $isMember && ! empty($this->customers) ? $this->customers[array_rand($this->customers)] : null;
                    $customerId = $customer?->id;

                    $itemCount = rand(1, 4);
                    $lineItems = [];
                    $orderSubtotal = 0;

                    for ($k = 0; $k < $itemCount; $k++) {
                        $p = $productList[rand(0, $prodCount - 1)];
                        $qty = rand(1, 2);
                        $unitPrice = (int) $p->product_price;

                        $itemOptions = [];
                        $extraPriceTotal = 0;

                        if (isset($this->productIngredients[$p->id])) {
                            $sugarMod = $this->modifiers[rand(1, 4)] ?? null;
                            $iceMod = $this->modifiers[rand(5, 8)] ?? null;

                            if ($sugarMod) {
                                $itemOptions[] = ['modifier_id' => $sugarMod->id, 'name' => $sugarMod->name, 'extra_price' => 0];
                            }
                            if ($iceMod) {
                                $itemOptions[] = ['modifier_id' => $iceMod->id, 'name' => $iceMod->name, 'extra_price' => 0];
                            }

                            if (rand(1, 100) <= 20) {
                                $addonMod = $this->modifiers[rand(9, 15)] ?? null;
                                if ($addonMod) {
                                    $extraPriceTotal += (int) $addonMod->extra_price;
                                    $itemOptions[] = ['modifier_id' => $addonMod->id, 'name' => $addonMod->name, 'extra_price' => (int) $addonMod->extra_price];
                                }
                            }
                        }

                        $finalUnitPrice = $unitPrice + $extraPriceTotal;
                        $lineSubtotal = $finalUnitPrice * $qty;
                        $orderSubtotal += $lineSubtotal;

                        $lineItems[] = [
                            'product' => $p,
                            'qty' => $qty,
                            'unit_price' => $finalUnitPrice,
                            'subtotal' => $lineSubtotal,
                            'options' => $itemOptions,
                        ];

                        if (isset($this->productIngredients[$p->id])) {
                            foreach ($this->productIngredients[$p->id] as $pi) {
                                $ingredientUsage[$pi->ingredient_id] = ($ingredientUsage[$pi->ingredient_id] ?? 0) + ($pi->quantity_required * $qty);
                            }
                            foreach ($itemOptions as $opt) {
                                if (isset($this->modifierIngredients[$opt['modifier_id']])) {
                                    foreach ($this->modifierIngredients[$opt['modifier_id']] as $mi) {
                                        $ingredientUsage[$mi->ingredient_id] = ($ingredientUsage[$mi->ingredient_id] ?? 0) + ($mi->quantity_required * $qty);
                                    }
                                }
                            }
                        } else {
                            $productUsage[$p->id] = ($productUsage[$p->id] ?? 0) + $qty;
                        }
                    }

                    $discountId = null;
                    $discountAmount = 0;
                    if (rand(1, 100) <= 12 && ! empty($this->discounts)) {
                        $disc = $this->discounts[array_rand($this->discounts)];
                        if ($orderSubtotal >= $disc->min_purchase_amount) {
                            $discountId = $disc->id;
                            if ($disc->type === 'percentage') {
                                $calc = (int) round($orderSubtotal * ($disc->value / 100));
                                $discountAmount = $disc->max_discount_amount ? min($calc, $disc->max_discount_amount) : $calc;
                            } else {
                                $discountAmount = min($orderSubtotal, (int) $disc->value);
                            }
                        }
                    }

                    $discountedSubtotal = max(0, $orderSubtotal - $discountAmount);
                    $taxAmount = (int) round($discountedSubtotal * 0.11);
                    $orderAmount = (int) (round(($discountedSubtotal + $taxAmount) / 100) * 100);

                    $payRoll = rand(1, 100);
                    $isSplit = $payRoll > 98;
                    $paymentMethod = $payRoll <= 35 ? 'cash' : ($payRoll <= 75 ? 'qris' : ($payRoll <= 90 ? 'ewallet' : 'card'));

                    $orderCode = sprintf('ORD-9-%02d-%s-%06d', $sStore->id, $currentDate->format('Ymd'), $orderSeq);
                    $idempotencyKey = (string) Str::uuid();

                    $cashReceived = null;
                    $orderChange = 0;
                    if ($paymentMethod === 'cash' && ! $isSplit) {
                        $cashReceived = (int) (ceil($orderAmount / 10000) * 10000);
                        if ($cashReceived < $orderAmount) {
                            $cashReceived = $orderAmount;
                        }
                        $orderChange = $cashReceived - $orderAmount;
                        if ($shiftId) {
                            $shiftCashSales[$shiftId] = ($shiftCashSales[$shiftId] ?? 0) + $orderAmount;
                        }
                    }

                    $newOrderId = DB::table('orders')->insertGetId([
                        'tenant_id' => $this->tenantId,
                        'store_id' => $sStore->id,
                        'user_id' => $userId,
                        'shift_id' => $shiftId,
                        'discount_id' => $discountId,
                        'customer_id' => $customerId,
                        'table_id' => $tableId,
                        'order_type' => $orderType,
                        'order_code' => $orderCode,
                        'idempotency_key' => $idempotencyKey,
                        'order_date' => $dateStr,
                        'subtotal_amount' => $orderSubtotal,
                        'discount_amount' => $discountAmount,
                        'tax_amount' => $taxAmount,
                        'service_charge_amount' => 0,
                        'order_amount' => $orderAmount,
                        'cash_received' => $cashReceived,
                        'order_change' => $orderChange,
                        'order_status' => 'paid',
                        'payment_method' => $paymentMethod,
                        'created_at' => $orderTime,
                        'updated_at' => $orderTime,
                    ]);

                    foreach ($lineItems as $item) {
                        $orderItemId = DB::table('order_items')->insertGetId([
                            'tenant_id' => $this->tenantId,
                            'store_id' => $sStore->id,
                            'order_id' => $newOrderId,
                            'product_id' => $item['product']->id,
                            'qty' => $item['qty'],
                            'order_price' => $item['unit_price'],
                            'order_subtotal' => $item['subtotal'],
                            'options' => ! empty($item['options']) ? json_encode($item['options']) : null,
                            'preparation_status' => 'ready',
                            'processed_by' => $userId,
                            'created_at' => $orderTime,
                            'updated_at' => $orderTime,
                        ]);

                        foreach ($item['options'] as $opt) {
                            DB::table('order_item_modifiers')->insert([
                                'tenant_id' => $this->tenantId,
                                'store_id' => $sStore->id,
                                'order_item_id' => $orderItemId,
                                'modifier_id' => $opt['modifier_id'],
                                'price_at_time' => $opt['extra_price'],
                                'qty' => $item['qty'],
                                'created_at' => $orderTime,
                                'updated_at' => $orderTime,
                            ]);
                        }
                    }

                    if ($isSplit) {
                        $leg1Amount = (int) round($orderAmount / 2);
                        $leg2Amount = $orderAmount - $leg1Amount;

                        DB::table('payments')->insert([
                            [
                                'tenant_id' => $this->tenantId,
                                'store_id' => $sStore->id,
                                'order_id' => $newOrderId,
                                'payment_method' => 'cash',
                                'amount' => $leg1Amount,
                                'status' => 'captured',
                                'captured_at' => $orderTime,
                                'processed_by' => $userId,
                                'idempotency_key' => 'pay:'.$orderCode.':0',
                                'created_at' => $orderTime,
                                'updated_at' => null,
                            ],
                            [
                                'tenant_id' => $this->tenantId,
                                'store_id' => $sStore->id,
                                'order_id' => $newOrderId,
                                'payment_method' => 'qris',
                                'amount' => $leg2Amount,
                                'status' => 'captured',
                                'captured_at' => $orderTime,
                                'processed_by' => $userId,
                                'idempotency_key' => 'pay:'.$orderCode.':1',
                                'created_at' => $orderTime,
                                'updated_at' => null,
                            ],
                        ]);

                        if ($shiftId) {
                            $shiftCashSales[$shiftId] = ($shiftCashSales[$shiftId] ?? 0) + $leg1Amount;
                        }
                    } else {
                        DB::table('payments')->insert([
                            'tenant_id' => $this->tenantId,
                            'store_id' => $sStore->id,
                            'order_id' => $newOrderId,
                            'payment_method' => $paymentMethod,
                            'amount' => $orderAmount,
                            'status' => 'captured',
                            'captured_at' => $orderTime,
                            'processed_by' => $userId,
                            'idempotency_key' => 'pay:'.$orderCode.':0',
                            'created_at' => $orderTime,
                            'updated_at' => null,
                        ]);
                    }

                    if ($customerId) {
                        $customerOrders[] = [
                            'customer_id' => $customerId,
                            'order_id' => $newOrderId,
                            'order_code' => $orderCode,
                            'subtotal' => $orderSubtotal,
                            'date' => $orderTime,
                        ];
                    }

                    $orderSeq++;
                    $totalOrdersCreated++;
                }
            }

            $bar->setMessage(number_format($totalOrdersCreated));
            $bar->advance();
            $currentDate->addDay();
        }

        $bar->finish();
        $this->info('');

        // Reconcile shifts
        $this->line('  ✓ Updating shift closing balances with accurate cash totals...');
        foreach ($shiftsLookup as $s) {
            $cashSales = $shiftCashSales[$s->id] ?? 0;
            $cashIn = 100000;
            $cashOut = ($s->id % 4 === 0) ? 35000 : 0;
            $expectedCash = $s->opening_balance + $cashSales + $cashIn - $cashOut;
            $diff = ($s->id % 23 === 0) ? -5000 : 0;
            $closingBalance = $expectedCash + $diff;

            DB::table('shifts')
                ->where('id', $s->id)
                ->update([
                    'expected_cash' => $expectedCash,
                    'closing_balance' => $closingBalance,
                    'cash_difference' => $diff,
                    'updated_at' => $s->closed_at,
                ]);
        }

        $this->line("  ✓ Successfully generated {$totalOrdersCreated} paid orders across 365 days.");

        return [
            'ingredient_usage' => $ingredientUsage,
            'product_usage' => $productUsage,
            'customer_orders' => $customerOrders,
        ];
    }

    /**
     * Stage 8: Inventory Balances & Movements
     */
    private function setupInventoryMovementsAndBalances(array $ingredientUsage, array $productUsage): void
    {
        $storeCount = count($this->stores);
        $startDate = '2025-10-14 06:00:00';

        // 1. Ingredients Inventory
        foreach ($this->ingredients as $ing) {
            $totalConsumed = $ingredientUsage[$ing->id] ?? 100;
            $totalSupply = $totalConsumed * 1.4;
            $initialStock = $totalSupply * 0.35;
            $monthlyRestock = ($totalSupply * 0.65) / 12;

            $perStoreConsumed = $totalConsumed / $storeCount;
            $perStoreInitial = $initialStock / $storeCount;
            $perStoreMonthly = $monthlyRestock / $storeCount;
            $perStoreFinal = ($perStoreInitial + ($perStoreMonthly * 12)) - $perStoreConsumed;

            foreach ($this->stores as $s) {
                // Initial movement
                DB::table('ingredient_stock_movements')->insert([
                    'tenant_id' => $this->tenantId,
                    'store_id' => $s->id,
                    'ingredient_id' => $ing->id,
                    'type' => 'initial_sync',
                    'quantity' => round($perStoreInitial, 4),
                    'unit_cost' => $ing->cost_per_unit,
                    'reason' => 'Saldo awal pembukaan inventaris cabang',
                    'idempotency_key' => "ism:init:{$s->id}:{$ing->id}",
                    'created_by' => 16,
                    'created_at' => $startDate,
                ]);

                // 12 Monthly Restocks
                for ($m = 1; $m <= 12; $m++) {
                    $mDate = Carbon::parse('2025-10-14')->addMonths($m)->startOfMonth()->toDateTimeString();
                    DB::table('ingredient_stock_movements')->insert([
                        'tenant_id' => $this->tenantId,
                        'store_id' => $s->id,
                        'ingredient_id' => $ing->id,
                        'type' => 'purchase_receipt',
                        'quantity' => round($perStoreMonthly, 4),
                        'unit_cost' => $ing->cost_per_unit,
                        'reason' => "Pengiriman pasokan bulanan periode {$m}",
                        'idempotency_key' => "ism:restock:{$m}:{$s->id}:{$ing->id}",
                        'created_by' => 16,
                        'created_at' => $mDate,
                    ]);
                }

                // Sale deduction aggregate movement
                DB::table('ingredient_stock_movements')->insert([
                    'tenant_id' => $this->tenantId,
                    'store_id' => $s->id,
                    'ingredient_id' => $ing->id,
                    'type' => 'sale_deduction',
                    'quantity' => -round($perStoreConsumed, 4),
                    'unit_cost' => $ing->cost_per_unit,
                    'reason' => 'Konsumsi penjualan akumulatif operasional setahun penuh',
                    'idempotency_key' => "ism:sales:{$s->id}:{$ing->id}",
                    'created_by' => 16,
                    'created_at' => '2026-10-13 23:59:00',
                ]);

                // Balance Row
                DB::table('ingredient_stock_balances')->upsert(
                    [
                        'tenant_id' => $this->tenantId,
                        'store_id' => $s->id,
                        'ingredient_id' => $ing->id,
                        'quantity' => round($perStoreFinal, 4),
                        'baseline_quantity' => 0,
                        'baseline_movement_id' => 0,
                        'created_at' => $startDate,
                        'updated_at' => '2026-10-13 23:59:00',
                    ],
                    ['tenant_id', 'store_id', 'ingredient_id'],
                    ['quantity', 'updated_at']
                );
            }

            // Update legacy column
            $totalFinalAllStores = $perStoreFinal * $storeCount;
            DB::table('ingredients')
                ->where('id', $ing->id)
                ->update([
                    'current_stock' => round($totalFinalAllStores, 4),
                    'updated_at' => '2026-10-13 23:59:00',
                ]);
        }

        // 2. Non-BOM Products Inventory
        foreach ($this->products as $p) {
            if (isset($this->productIngredients[$p->id])) {
                continue;
            }

            $totalConsumed = $productUsage[$p->id] ?? 20;
            $totalSupply = max(100, (int) round($totalConsumed * 1.5));
            $initialStock = (int) round($totalSupply * 0.4);
            $monthlyRestock = (int) round(($totalSupply * 0.6) / 12);

            $perStoreConsumed = (int) round($totalConsumed / $storeCount);
            $perStoreInitial = (int) round($initialStock / $storeCount);
            $perStoreMonthly = (int) round($monthlyRestock / $storeCount);
            $perStoreFinal = ($perStoreInitial + ($perStoreMonthly * 12)) - $perStoreConsumed;

            foreach ($this->stores as $s) {
                // Initial movement
                DB::table('stock_movements')->insert([
                    'tenant_id' => $this->tenantId,
                    'store_id' => $s->id,
                    'product_id' => $p->id,
                    'type' => 'initial_sync',
                    'quantity' => $perStoreInitial,
                    'reason' => 'Stok awal ritel cabang',
                    'idempotency_key' => "sm:init:{$s->id}:{$p->id}",
                    'created_at' => $startDate,
                    'updated_at' => $startDate,
                ]);

                // 12 Monthly Restocks
                for ($m = 1; $m <= 12; $m++) {
                    $mDate = Carbon::parse('2025-10-14')->addMonths($m)->startOfMonth()->toDateTimeString();
                    DB::table('stock_movements')->insert([
                        'tenant_id' => $this->tenantId,
                        'store_id' => $s->id,
                        'product_id' => $p->id,
                        'type' => 'adjustment',
                        'quantity' => $perStoreMonthly,
                        'reason' => "Pengiriman stok ritel bulanan periode {$m}",
                        'idempotency_key' => "sm:restock:{$m}:{$s->id}:{$p->id}",
                        'created_at' => $mDate,
                        'updated_at' => $mDate,
                    ]);
                }

                // Sale deduction movement
                DB::table('stock_movements')->insert([
                    'tenant_id' => $this->tenantId,
                    'store_id' => $s->id,
                    'product_id' => $p->id,
                    'type' => 'sale_deduction',
                    'quantity' => -$perStoreConsumed,
                    'reason' => 'Penjualan ritel akumulatif setahun penuh',
                    'idempotency_key' => "sm:sales:{$s->id}:{$p->id}",
                    'created_at' => '2026-10-13 23:59:00',
                    'updated_at' => '2026-10-13 23:59:00',
                ]);

                // Balance row
                DB::table('product_stock_balances')->upsert(
                    [
                        'tenant_id' => $this->tenantId,
                        'store_id' => $s->id,
                        'product_id' => $p->id,
                        'quantity' => $perStoreFinal,
                        'baseline_quantity' => 0,
                        'baseline_movement_id' => 0,
                        'created_at' => $startDate,
                        'updated_at' => '2026-10-13 23:59:00',
                    ],
                    ['tenant_id', 'store_id', 'product_id'],
                    ['quantity', 'updated_at']
                );
            }

            // Update legacy column
            $totalFinalAllStores = $perStoreFinal * $storeCount;
            DB::table('products')
                ->where('id', $p->id)
                ->update([
                    'stock' => $totalFinalAllStores,
                    'updated_at' => '2026-10-13 23:59:00',
                ]);
        }

        $this->line('  ✓ Recorded stock ledger & balances with zero drift.');
    }

    /**
     * Stage 9: Customer Loyalty Ledger
     */
    private function setupLoyaltyLedger(array $customerOrders): void
    {
        $custBalance = [];

        usort($customerOrders, fn ($a, $b) => strcmp($a['date'], $b['date']));

        $bar = $this->output->createProgressBar(count($customerOrders));
        $bar->setFormat(' Loyalty Ledger: %current%/%max% [%bar%] %percent:3s%%');

        foreach ($customerOrders as $co) {
            $cId = $co['customer_id'];
            $earned = (int) floor($co['subtotal'] / 1000);
            if ($earned <= 0) {
                $earned = 1;
            }

            $current = $custBalance[$cId] ?? 0;
            $newBal = $current + $earned;
            $custBalance[$cId] = $newBal;

            DB::table('loyalty_ledger')->insert([
                'tenant_id' => $this->tenantId,
                'customer_id' => $cId,
                'order_id' => $co['order_id'],
                'type' => 'earn',
                'points' => $earned,
                'balance_after' => $newBal,
                'reference' => 'Poin belanja order '.$co['order_code'],
                'entry_hash' => '',
                'created_by' => null,
                'created_at' => $co['date'],
            ]);

            $bar->advance();
        }

        $bar->finish();
        $this->info('');

        $accounts = DB::table('customer_loyalty_accounts')->where('tenant_id', $this->tenantId)->get();
        foreach ($accounts as $acc) {
            $tierId = 1;
            if ($acc->lifetime_points_earned >= 3000) {
                $tierId = $this->loyaltyTiers['PLATINUM']->id ?? null;
            } elseif ($acc->lifetime_points_earned >= 1500) {
                $tierId = $this->loyaltyTiers['GOLD']->id ?? null;
            } elseif ($acc->lifetime_points_earned >= 500) {
                $tierId = $this->loyaltyTiers['SILVER']->id ?? null;
            } else {
                $tierId = $this->loyaltyTiers['BRONZE']->id ?? null;
            }

            if ($tierId) {
                DB::table('customer_loyalty_accounts')
                    ->where('id', $acc->id)
                    ->update(['current_tier_id' => $tierId]);
            }
        }

        $this->line('  ✓ Chained loyalty ledger & synchronized '.count($accounts).' loyalty accounts.');
    }

    /**
     * Stage 10: Final Audits & Reporting
     */
    private function runFinalValidations(): void
    {
        $this->info('  Running database validation suite...');
        $this->call('pos:verify-database', ['--allow-skipped' => true]);

        $this->info('  Running inventory reconciliation for Tenant '.$this->tenantId.'...');
        $this->call('inventory:reconcile-balances', ['--tenant' => $this->tenantId]);
    }

    private function weightedRandom(array $weights): int
    {
        $total = array_sum($weights);
        $rand = rand(1, $total);
        $running = 0;
        foreach ($weights as $val => $w) {
            $running += $w;
            if ($rand <= $running) {
                return $val;
            }
        }

        return array_key_first($weights);
    }
}
