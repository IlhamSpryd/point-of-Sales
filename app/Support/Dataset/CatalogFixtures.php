<?php

declare(strict_types=1);

namespace App\Support\Dataset;

/**
 * Katalog referensi bisnis untuk generator.
 *
 * Semua entri di sini adalah DATA (bukan logika): nama kategori, produk,
 * resep BOM, bahan baku, pemasok, dan referensi nama orang/alamat.
 * Generator memilih subset sesuai konfigurasi dan menurunkan harga/biaya
 * secara deterministik, sehingga tidak ada angka ajaib tersebar di kode.
 */
final class CatalogFixtures
{
    /**
     * Kategori F&B: code => [name, sort, weight].
     *
     * @return array<int, array{code:string,name:string,weight:int,income:bool}>
     */
    public static function categories(): array
    {
        return [
            ['code' => 'ESP', 'name' => 'Espresso Bar', 'weight' => 90, 'income' => true],
            ['code' => 'SIG', 'name' => 'Signature Coffee', 'weight' => 100, 'income' => true],
            ['code' => 'MLK', 'name' => 'Milk-Based Coffee', 'weight' => 95, 'income' => true],
            ['code' => 'TEA', 'name' => 'Tea & Non-Coffee', 'weight' => 60, 'income' => true],
            ['code' => 'FRP', 'name' => 'Frappe & Blended', 'weight' => 45, 'income' => true],
            ['code' => 'PST', 'name' => 'Pastry & Bakery', 'weight' => 70, 'income' => true],
            ['code' => 'BRD', 'name' => 'Bread & Toast', 'weight' => 55, 'income' => true],
            ['code' => 'RIC', 'name' => 'Rice Bowl & Mains', 'weight' => 65, 'income' => true],
            ['code' => 'PAS', 'name' => 'Pasta & Western', 'weight' => 40, 'income' => true],
            ['code' => 'SNK', 'name' => 'Light Bites', 'weight' => 50, 'income' => true],
            ['code' => 'DSR', 'name' => 'Dessert', 'weight' => 35, 'income' => true],
            ['code' => 'RET', 'name' => 'Retail Beans & Merch', 'weight' => 15, 'income' => true],
        ];
    }

    /**
     * Bahan baku: [code, name, unit, cost (Rp/satuan), reorder, initial_multiplier, supplier_group].
     *
     * @return array<int, array{code:string,name:string,unit:string,cost:float,reorder:float,supplier:string}>
     */
    public static function ingredients(): array
    {
        return [
            ['code' => 'BEAN-HOUSE', 'name' => 'Biji Kopi House Blend Arabica', 'unit' => 'g', 'cost' => 300, 'reorder' => 6000, 'supplier' => 'GRN'],
            ['code' => 'BEAN-GAYO', 'name' => 'Biji Kopi Aceh Gayo Natural', 'unit' => 'g', 'cost' => 450, 'reorder' => 3000, 'supplier' => 'GRN'],
            ['code' => 'BEAN-TOR', 'name' => 'Biji Kopi Toraja Sapan Washed', 'unit' => 'g', 'cost' => 480, 'reorder' => 3000, 'supplier' => 'GRN'],
            ['code' => 'BEAN-BALI', 'name' => 'Biji Kopi Bali Kintamani', 'unit' => 'g', 'cost' => 420, 'reorder' => 3000, 'supplier' => 'GRN'],
            ['code' => 'BEAN-ETH', 'name' => 'Biji Kopi Ethiopia Yirgacheffe', 'unit' => 'g', 'cost' => 700, 'reorder' => 2500, 'supplier' => 'GRN'],
            ['code' => 'BEAN-DECA', 'name' => 'Biji Kopi Decaf Colombia', 'unit' => 'g', 'cost' => 560, 'reorder' => 2000, 'supplier' => 'GRN'],
            ['code' => 'MILK-FRESH', 'name' => 'Susu Segar Pasteurisasi', 'unit' => 'ml', 'cost' => 20, 'reorder' => 25000, 'supplier' => 'DAI'],
            ['code' => 'MILK-OAT', 'name' => 'Oat Milk Barista Edition', 'unit' => 'ml', 'cost' => 45, 'reorder' => 15000, 'supplier' => 'DAI'],
            ['code' => 'MILK-ALM', 'name' => 'Almond Milk Unsweetened', 'unit' => 'ml', 'cost' => 50, 'reorder' => 12000, 'supplier' => 'DAI'],
            ['code' => 'MILK-SKM', 'name' => 'Krimer Kental Manis', 'unit' => 'ml', 'cost' => 25, 'reorder' => 10000, 'supplier' => 'DAI'],
            ['code' => 'CREAM-WHIP', 'name' => 'Heavy Whipping Cream', 'unit' => 'ml', 'cost' => 62, 'reorder' => 8000, 'supplier' => 'DAI'],
            ['code' => 'SYR-AREN', 'name' => 'Sirup Gula Aren Asli', 'unit' => 'ml', 'cost' => 35, 'reorder' => 12000, 'supplier' => 'SYR'],
            ['code' => 'SYR-VAN', 'name' => 'Sirup Vanilla', 'unit' => 'ml', 'cost' => 65, 'reorder' => 6000, 'supplier' => 'SYR'],
            ['code' => 'SYR-CAR', 'name' => 'Sirup Salted Caramel', 'unit' => 'ml', 'cost' => 70, 'reorder' => 6000, 'supplier' => 'SYR'],
            ['code' => 'SYR-HAZ', 'name' => 'Sirup Hazelnut', 'unit' => 'ml', 'cost' => 65, 'reorder' => 6000, 'supplier' => 'SYR'],
            ['code' => 'SYR-PAN', 'name' => 'Sirup Pandan', 'unit' => 'ml', 'cost' => 40, 'reorder' => 5000, 'supplier' => 'SYR'],
            ['code' => 'SYR-SIMPLE', 'name' => 'Simple Syrup', 'unit' => 'ml', 'cost' => 15, 'reorder' => 18000, 'supplier' => 'SYR'],
            ['code' => 'PWD-MATCHA', 'name' => 'Bubuk Matcha Premium', 'unit' => 'g', 'cost' => 900, 'reorder' => 2500, 'supplier' => 'PWD'],
            ['code' => 'PWD-CHOCO', 'name' => 'Bubuk Dark Chocolate 70%', 'unit' => 'g', 'cost' => 350, 'reorder' => 6000, 'supplier' => 'PWD'],
            ['code' => 'PWD-RED', 'name' => 'Bubuk Red Velvet', 'unit' => 'g', 'cost' => 280, 'reorder' => 3000, 'supplier' => 'PWD'],
            ['code' => 'PWD-TARO', 'name' => 'Bubuk Taro', 'unit' => 'g', 'cost' => 250, 'reorder' => 3000, 'supplier' => 'PWD'],
            ['code' => 'TEA-EARL', 'name' => 'Teh Earl Grey', 'unit' => 'g', 'cost' => 400, 'reorder' => 2000, 'supplier' => 'PWD'],
            ['code' => 'TEA-JAS', 'name' => 'Teh Jasmine Hijau', 'unit' => 'g', 'cost' => 300, 'reorder' => 3000, 'supplier' => 'PWD'],
            ['code' => 'TEA-CHA', 'name' => 'Bunga Chamomile Kering', 'unit' => 'g', 'cost' => 550, 'reorder' => 1200, 'supplier' => 'PWD'],
            ['code' => 'TEA-MINT', 'name' => 'Daun Peppermint Kering', 'unit' => 'g', 'cost' => 500, 'reorder' => 1200, 'supplier' => 'PWD'],
            ['code' => 'RICE', 'name' => 'Beras Pandan Wangi', 'unit' => 'g', 'cost' => 18, 'reorder' => 25000, 'supplier' => 'DRY'],
            ['code' => 'BEEF', 'name' => 'Daging Sapi Slice', 'unit' => 'g', 'cost' => 180, 'reorder' => 12000, 'supplier' => 'PRT'],
            ['code' => 'CHICKEN', 'name' => 'Dada Ayam Fillet', 'unit' => 'g', 'cost' => 70, 'reorder' => 18000, 'supplier' => 'PRT'],
            ['code' => 'PASTA', 'name' => 'Pasta Spaghetti', 'unit' => 'g', 'cost' => 45, 'reorder' => 9000, 'supplier' => 'DRY'],
            ['code' => 'EGG', 'name' => 'Telur Ayam', 'unit' => 'pcs', 'cost' => 2500, 'reorder' => 600, 'supplier' => 'PRT'],
            ['code' => 'BUTTER', 'name' => 'Butter French', 'unit' => 'g', 'cost' => 220, 'reorder' => 6000, 'supplier' => 'DAI'],
            ['code' => 'FLOUR', 'name' => 'Tepung Terigu Protein Tinggi', 'unit' => 'g', 'cost' => 15, 'reorder' => 20000, 'supplier' => 'DRY'],
            ['code' => 'OIL', 'name' => 'Minyak Goreng', 'unit' => 'ml', 'cost' => 22, 'reorder' => 12000, 'supplier' => 'DRY'],
            ['code' => 'CHEESE-CHD', 'name' => 'Keju Cheddar Shredded', 'unit' => 'g', 'cost' => 120, 'reorder' => 5000, 'supplier' => 'DAI'],
            ['code' => 'CHEESE-MOZ', 'name' => 'Keju Mozzarella', 'unit' => 'g', 'cost' => 160, 'reorder' => 5000, 'supplier' => 'DAI'],
            ['code' => 'BRIOCHE', 'name' => 'Roti Brioche Slice', 'unit' => 'pcs', 'cost' => 4000, 'reorder' => 400, 'supplier' => 'BAK'],
            ['code' => 'SOURDOUGH', 'name' => 'Roti Sourdough', 'unit' => 'pcs', 'cost' => 6500, 'reorder' => 250, 'supplier' => 'BAK'],
            ['code' => 'GARLIC', 'name' => 'Bawang Putih Giling', 'unit' => 'g', 'cost' => 50, 'reorder' => 2500, 'supplier' => 'PRT'],
            ['code' => 'CHILI', 'name' => 'Cabai Rawit Merah', 'unit' => 'g', 'cost' => 60, 'reorder' => 2500, 'supplier' => 'PRT'],
            ['code' => 'ONION', 'name' => 'Bawang Bombay', 'unit' => 'g', 'cost' => 35, 'reorder' => 4000, 'supplier' => 'PRT'],
            ['code' => 'POTATO', 'name' => 'Kentang Potong', 'unit' => 'g', 'cost' => 28, 'reorder' => 8000, 'supplier' => 'PRT'],
            ['code' => 'LEMON', 'name' => 'Lemon Segar', 'unit' => 'pcs', 'cost' => 3500, 'reorder' => 120, 'supplier' => 'PRT'],
            ['code' => 'CUP-COLD-16', 'name' => 'Cup Plastik 16oz', 'unit' => 'pcs', 'cost' => 1200, 'reorder' => 12000, 'supplier' => 'PKG'],
            ['code' => 'CUP-COLD-12', 'name' => 'Cup Plastik 12oz', 'unit' => 'pcs', 'cost' => 1000, 'reorder' => 10000, 'supplier' => 'PKG'],
            ['code' => 'CUP-HOT-8', 'name' => 'Cup Kertas Hot 8oz', 'unit' => 'pcs', 'cost' => 900, 'reorder' => 9000, 'supplier' => 'PKG'],
            ['code' => 'LID-FLAT', 'name' => 'Flat Lid 16oz', 'unit' => 'pcs', 'cost' => 350, 'reorder' => 12000, 'supplier' => 'PKG'],
            ['code' => 'LID-DOME', 'name' => 'Dome Lid 16oz', 'unit' => 'pcs', 'cost' => 400, 'reorder' => 9000, 'supplier' => 'PKG'],
            ['code' => 'LID-HOT', 'name' => 'Sip Lid Hot 8oz', 'unit' => 'pcs', 'cost' => 350, 'reorder' => 9000, 'supplier' => 'PKG'],
            ['code' => 'STRAW', 'name' => 'Paper Straw', 'unit' => 'pcs', 'cost' => 250, 'reorder' => 14000, 'supplier' => 'PKG'],
            ['code' => 'BOX-FOOD', 'name' => 'Food Box Eco', 'unit' => 'pcs', 'cost' => 1800, 'reorder' => 6000, 'supplier' => 'PKG'],
            ['code' => 'BAG-L', 'name' => 'Paper Bag Large', 'unit' => 'pcs', 'cost' => 2500, 'reorder' => 4000, 'supplier' => 'PKG'],
            ['code' => 'BAG-M', 'name' => 'Paper Bag Medium', 'unit' => 'pcs', 'cost' => 1800, 'reorder' => 5000, 'supplier' => 'PKG'],
            ['code' => 'ICE', 'name' => 'Es Kristal', 'unit' => 'g', 'cost' => 5, 'reorder' => 60000, 'supplier' => 'DRY'],
            ['code' => 'SUGAR', 'name' => 'Gula Pasir', 'unit' => 'g', 'cost' => 16, 'reorder' => 15000, 'supplier' => 'DRY'],
            ['code' => 'SALT', 'name' => 'Garam Halus', 'unit' => 'g', 'cost' => 8, 'reorder' => 5000, 'supplier' => 'DRY'],
            ['code' => 'VAN-EXT', 'name' => 'Ekstrak Vanilla', 'unit' => 'ml', 'cost' => 180, 'reorder' => 1500, 'supplier' => 'SYR'],
            ['code' => 'YEAST', 'name' => 'Ragi Instan', 'unit' => 'g', 'cost' => 90, 'reorder' => 1500, 'supplier' => 'BAK'],
            ['code' => 'CINNAMON', 'name' => 'Bubuk Kayu Manis', 'unit' => 'g', 'cost' => 130, 'reorder' => 1200, 'supplier' => 'PWD'],
        ];
    }

    /**
     * Pemasok: group => [name, terms_days, lead_time, tax_id_suffix].
     *
     * @return array<string, array{name:string,terms:int,lead:int}>
     */
    public static function suppliers(): array
    {
        return [
            'GRN' => ['name' => 'Nusantara Green Beans', 'terms' => 14, 'lead' => 2],
            'DAI' => ['name' => 'Prima Dairy Distribution', 'terms' => 7, 'lead' => 1],
            'SYR' => ['name' => 'Aroma Syrup Indonesia', 'terms' => 21, 'lead' => 3],
            'PWD' => ['name' => 'Global Powder Supply', 'terms' => 14, 'lead' => 3],
            'DRY' => ['name' => 'Sinar Dry Goods', 'terms' => 7, 'lead' => 2],
            'PRT' => ['name' => 'Segar Pasar Protein', 'terms' => 3, 'lead' => 1],
            'BAK' => ['name' => 'Artisan Bakery Supply', 'terms' => 7, 'lead' => 1],
            'PKG' => ['name' => 'Eco Packaging Nusantara', 'terms' => 30, 'lead' => 4],
        ];
    }

    /**
     * Resep menu: code => [name, category_code, price, [ingredient_code => qty], popularity(1-10)].
     * Produk tanpa BOM (retail) memakai key 'retail' => true.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function products(): array
    {
        return [
            // Espresso Bar
            self::p('ESP-01', 'Espresso Single Shot', 'ESP', 22000, ['BEAN-HOUSE' => 18, 'CUP-HOT-8' => 1], 6),
            self::p('ESP-02', 'Espresso Double Shot', 'ESP', 28000, ['BEAN-HOUSE' => 24, 'CUP-HOT-8' => 1], 6),
            self::p('ESP-03', 'Americano Hot', 'ESP', 28000, ['BEAN-HOUSE' => 18, 'CUP-HOT-8' => 1, 'LID-HOT' => 1], 8),
            self::p('ESP-04', 'Iced Americano', 'ESP', 30000, ['BEAN-HOUSE' => 18, 'ICE' => 150, 'CUP-COLD-16' => 1, 'LID-FLAT' => 1, 'STRAW' => 1], 10),
            self::p('ESP-05', 'Long Black Hot', 'ESP', 30000, ['BEAN-HOUSE' => 20, 'CUP-HOT-8' => 1, 'LID-HOT' => 1], 5),
            self::p('ESP-06', 'Piccolo Latte', 'ESP', 32000, ['BEAN-HOUSE' => 18, 'MILK-FRESH' => 100, 'CUP-HOT-8' => 1], 5),
            self::p('ESP-07', 'Ristretto Doppio', 'ESP', 27000, ['BEAN-HOUSE' => 20, 'CUP-HOT-8' => 1], 3),
            // Signature
            self::p('SIG-01', 'Apeiron Aren Latte', 'SIG', 33000, ['BEAN-HOUSE' => 18, 'MILK-FRESH' => 140, 'SYR-AREN' => 25, 'ICE' => 120, 'CUP-COLD-16' => 1, 'LID-FLAT' => 1, 'STRAW' => 1], 10),
            self::p('SIG-02', 'Pandan Wangi Latte', 'SIG', 35000, ['BEAN-HOUSE' => 18, 'MILK-FRESH' => 140, 'SYR-PAN' => 25, 'ICE' => 120, 'CUP-COLD-16' => 1, 'LID-FLAT' => 1, 'STRAW' => 1], 9),
            self::p('SIG-03', 'Salted Caramel Macchiato', 'SIG', 38000, ['BEAN-HOUSE' => 18, 'MILK-FRESH' => 150, 'SYR-CAR' => 25, 'ICE' => 120, 'CUP-COLD-16' => 1, 'LID-FLAT' => 1, 'STRAW' => 1], 9),
            self::p('SIG-04', 'Butterscotch Cream Espresso', 'SIG', 40000, ['BEAN-HOUSE' => 20, 'MILK-FRESH' => 120, 'CREAM-WHIP' => 30, 'SYR-CAR' => 20, 'CUP-COLD-16' => 1, 'LID-DOME' => 1], 6),
            self::p('SIG-05', 'Kopi Susu Gula Aren', 'SIG', 28000, ['BEAN-HOUSE' => 18, 'MILK-FRESH' => 120, 'SYR-AREN' => 30, 'ICE' => 120, 'CUP-COLD-16' => 1, 'LID-FLAT' => 1, 'STRAW' => 1], 10),
            self::p('SIG-06', 'Cold Brew Aren Cream', 'SIG', 39000, ['BEAN-GAYO' => 25, 'MILK-FRESH' => 80, 'SYR-AREN' => 20, 'ICE' => 150, 'CUP-COLD-16' => 1, 'LID-DOME' => 1, 'STRAW' => 1], 7),
            // Milk-based
            self::p('MLK-01', 'Caffe Latte Hot', 'MLK', 35000, ['BEAN-HOUSE' => 18, 'MILK-FRESH' => 180, 'CUP-HOT-8' => 1, 'LID-HOT' => 1], 9),
            self::p('MLK-02', 'Iced Caffe Latte', 'MLK', 37000, ['BEAN-HOUSE' => 18, 'MILK-FRESH' => 160, 'ICE' => 120, 'CUP-COLD-16' => 1, 'LID-FLAT' => 1, 'STRAW' => 1], 10),
            self::p('MLK-03', 'Cappuccino Hot', 'MLK', 35000, ['BEAN-HOUSE' => 18, 'MILK-FRESH' => 150, 'CUP-HOT-8' => 1, 'LID-HOT' => 1], 8),
            self::p('MLK-04', 'Iced Cappuccino', 'MLK', 37000, ['BEAN-HOUSE' => 18, 'MILK-FRESH' => 130, 'ICE' => 120, 'CUP-COLD-16' => 1, 'LID-FLAT' => 1], 6),
            self::p('MLK-05', 'Vanilla Bean Latte', 'MLK', 40000, ['BEAN-HOUSE' => 18, 'MILK-FRESH' => 160, 'SYR-VAN' => 20, 'ICE' => 120, 'CUP-COLD-16' => 1, 'LID-FLAT' => 1], 7),
            self::p('MLK-06', 'Hazelnut Praline Latte', 'MLK', 40000, ['BEAN-HOUSE' => 18, 'MILK-FRESH' => 160, 'SYR-HAZ' => 20, 'ICE' => 120, 'CUP-COLD-16' => 1, 'LID-FLAT' => 1], 6),
            self::p('MLK-07', 'Caffe Mocha Belgian', 'MLK', 42000, ['BEAN-HOUSE' => 18, 'MILK-FRESH' => 150, 'PWD-CHOCO' => 25, 'ICE' => 120, 'CUP-COLD-16' => 1, 'LID-FLAT' => 1], 7),
            self::p('MLK-08', 'Flat White', 'MLK', 36000, ['BEAN-HOUSE' => 20, 'MILK-FRESH' => 160, 'CUP-HOT-8' => 1, 'LID-HOT' => 1], 7),
            // Tea & non-coffee
            self::p('TEA-01', 'Earl Grey Supreme Tea', 'TEA', 28000, ['TEA-EARL' => 5, 'CUP-HOT-8' => 1, 'LID-HOT' => 1], 4),
            self::p('TEA-02', 'Jasmine Green Blossom', 'TEA', 28000, ['TEA-JAS' => 5, 'CUP-HOT-8' => 1, 'LID-HOT' => 1], 4),
            self::p('TEA-03', 'Chamomile Dream Tisane', 'TEA', 30000, ['TEA-CHA' => 4, 'CUP-HOT-8' => 1, 'LID-HOT' => 1], 3),
            self::p('TEA-04', 'Iced Lychee Tea Breeze', 'TEA', 34000, ['TEA-JAS' => 5, 'SYR-SIMPLE' => 20, 'ICE' => 150, 'CUP-COLD-16' => 1, 'LID-FLAT' => 1, 'STRAW' => 1], 6),
            self::p('TEA-05', 'Iced Lemon Mint Tea', 'TEA', 32000, ['TEA-EARL' => 5, 'TEA-MINT' => 2, 'SYR-SIMPLE' => 20, 'ICE' => 150, 'LEMON' => 1, 'CUP-COLD-16' => 1, 'LID-FLAT' => 1], 5),
            self::p('TEA-06', 'Matcha Latte Iced', 'TEA', 40000, ['PWD-MATCHA' => 8, 'MILK-FRESH' => 160, 'SYR-SIMPLE' => 15, 'ICE' => 120, 'CUP-COLD-16' => 1, 'LID-FLAT' => 1, 'STRAW' => 1], 8),
            self::p('TEA-07', 'Matcha Latte Hot', 'TEA', 38000, ['PWD-MATCHA' => 8, 'MILK-FRESH' => 180, 'SYR-SIMPLE' => 15, 'CUP-HOT-8' => 1], 5),
            self::p('TEA-08', 'Dark Chocolate Iced', 'TEA', 38000, ['PWD-CHOCO' => 25, 'MILK-FRESH' => 160, 'ICE' => 120, 'CUP-COLD-16' => 1, 'LID-FLAT' => 1], 6),
            self::p('TEA-09', 'Red Velvet Velvetine', 'TEA', 38000, ['PWD-RED' => 25, 'MILK-FRESH' => 160, 'ICE' => 120, 'CUP-COLD-16' => 1, 'LID-DOME' => 1], 4),
            self::p('TEA-10', 'Taro Gourmet Latte', 'TEA', 36000, ['PWD-TARO' => 25, 'MILK-FRESH' => 160, 'ICE' => 120, 'CUP-COLD-16' => 1, 'LID-DOME' => 1], 4),
            // Frappe
            self::p('FRP-01', 'Mocha Chip Frappe', 'FRP', 45000, ['BEAN-HOUSE' => 18, 'PWD-CHOCO' => 25, 'MILK-FRESH' => 120, 'CREAM-WHIP' => 30, 'ICE' => 180, 'CUP-COLD-16' => 1, 'LID-DOME' => 1], 5),
            self::p('FRP-02', 'Caramel Crunch Frappe', 'FRP', 45000, ['BEAN-HOUSE' => 18, 'SYR-CAR' => 30, 'MILK-FRESH' => 120, 'CREAM-WHIP' => 30, 'ICE' => 180, 'CUP-COLD-16' => 1, 'LID-DOME' => 1], 5),
            self::p('FRP-03', 'Matcha Cream Frappe', 'FRP', 48000, ['PWD-MATCHA' => 10, 'MILK-FRESH' => 120, 'CREAM-WHIP' => 30, 'ICE' => 180, 'CUP-COLD-16' => 1, 'LID-DOME' => 1], 4),
            // Pastry & bakery
            self::p('PST-01', 'Cinnamon Morning Roll', 'PST', 28000, ['BUTTER' => 30, 'FLOUR' => 60, 'EGG' => 1, 'CINNAMON' => 5, 'BOX-FOOD' => 1], 6),
            self::p('PST-02', 'Fudgy Dark Choco Brownie', 'PST', 32000, ['PWD-CHOCO' => 35, 'BUTTER' => 30, 'FLOUR' => 40, 'EGG' => 1, 'BOX-FOOD' => 1], 6),
            self::p('PST-03', 'Butter Croissant', 'PST', 26000, ['BUTTER' => 45, 'FLOUR' => 60, 'BOX-FOOD' => 1], 8),
            self::p('PST-04', 'Almond Cream Croissant', 'PST', 36000, ['BUTTER' => 50, 'FLOUR' => 60, 'MILK-ALM' => 30, 'BOX-FOOD' => 1], 5),
            self::p('PST-05', 'Pain au Chocolat', 'PST', 32000, ['BUTTER' => 45, 'FLOUR' => 60, 'PWD-CHOCO' => 20, 'BOX-FOOD' => 1], 6),
            self::p('PST-06', 'Burnt Basque Cheesecake', 'PST', 42000, ['CREAM-WHIP' => 50, 'CHEESE-CHD' => 40, 'EGG' => 1, 'SUGAR' => 20, 'BOX-FOOD' => 1], 5),
            self::p('PST-07', 'Classic Tiramisu Cup', 'PST', 38000, ['BEAN-HOUSE' => 10, 'CREAM-WHIP' => 40, 'PWD-CHOCO' => 10, 'BOX-FOOD' => 1], 4),
            // Bread & toast
            self::p('BRD-01', 'Truffle Scrambled Egg Brioche', 'BRD', 45000, ['BRIOCHE' => 2, 'EGG' => 2, 'BUTTER' => 20, 'BOX-FOOD' => 1], 5),
            self::p('BRD-02', 'Smoked Beef & Cheddar Toast', 'BRD', 48000, ['SOURDOUGH' => 2, 'BEEF' => 60, 'CHEESE-CHD' => 30, 'BOX-FOOD' => 1], 5),
            self::p('BRD-03', 'Chicken Caesar Croissant', 'BRD', 52000, ['BUTTER' => 40, 'FLOUR' => 50, 'CHICKEN' => 80, 'CHEESE-CHD' => 20, 'BOX-FOOD' => 1], 4),
            // Rice bowls & mains
            self::p('RIC-01', 'Gyudon Beef Yakiniku Bowl', 'RIC', 58000, ['RICE' => 180, 'BEEF' => 90, 'EGG' => 1, 'GARLIC' => 10, 'BOX-FOOD' => 1], 6),
            self::p('RIC-02', 'Crispy Chicken Sambal Matah', 'RIC', 48000, ['RICE' => 180, 'CHICKEN' => 100, 'CHILI' => 20, 'OIL' => 20, 'BOX-FOOD' => 1], 7),
            self::p('RIC-03', 'Chicken Teriyaki Rice Bowl', 'RIC', 48000, ['RICE' => 180, 'CHICKEN' => 100, 'SYR-AREN' => 15, 'ONION' => 20, 'BOX-FOOD' => 1], 6),
            self::p('RIC-04', 'Nasi Goreng Apeiron Spesial', 'RIC', 46000, ['RICE' => 200, 'CHICKEN' => 50, 'EGG' => 1, 'GARLIC' => 10, 'CHILI' => 10, 'OIL' => 25, 'BOX-FOOD' => 1], 7),
            // Pasta
            self::p('PAS-01', 'Spaghetti Aglio Olio Beef', 'PAS', 52000, ['PASTA' => 100, 'BEEF' => 50, 'GARLIC' => 15, 'CHILI' => 10, 'OIL' => 20, 'BOX-FOOD' => 1], 4),
            self::p('PAS-02', 'Creamy Fettuccine Carbonara', 'PAS', 55000, ['PASTA' => 100, 'CREAM-WHIP' => 50, 'EGG' => 1, 'CHEESE-CHD' => 20, 'BEEF' => 40, 'BOX-FOOD' => 1], 4),
            // Light bites
            self::p('SNK-01', 'Truffle Fries with Aioli', 'SNK', 35000, ['POTATO' => 150, 'OIL' => 40, 'CHEESE-CHD' => 20, 'BOX-FOOD' => 1], 7),
            self::p('SNK-02', 'Crispy Chicken Wings', 'SNK', 42000, ['CHICKEN' => 150, 'FLOUR' => 30, 'OIL' => 50, 'BOX-FOOD' => 1], 6),
            self::p('SNK-03', 'Cireng Krispi Bumbu Rujak', 'SNK', 28000, ['FLOUR' => 100, 'OIL' => 40, 'SYR-AREN' => 30, 'BOX-FOOD' => 1], 5),
            // Retail (non-BOM)
            self::r('RET-01', 'House Blend Beans 250g', 'RET', 95000, 40),
            self::r('RET-02', 'Aceh Gayo Beans 200g', 'RET', 110000, 30),
            self::r('RET-03', 'Toraja Sapan Beans 200g', 'RET', 115000, 25),
            self::r('RET-04', 'Bali Kintamani Beans 200g', 'RET', 105000, 25),
            self::r('RET-05', 'Ethiopia Yirgacheffe 200g', 'RET', 165000, 20),
            self::r('RET-06', 'Drip Bag Coffee Box', 'RET', 65000, 50),
            self::r('RET-07', 'Ceramic Mug 350ml', 'RET', 85000, 20),
            self::r('RET-08', 'Stainless Tumbler 500ml', 'RET', 175000, 15),
            self::r('RET-09', 'Canvas Tote Bag', 'RET', 65000, 25),
            self::r('RET-10', 'V60 Dripper 02', 'RET', 125000, 10),
        ];
    }

    /** Helper pembuat produk BOM. */
    private static function p(string $code, string $name, string $cat, int $price, array $bom, int $popularity): array
    {
        return [
            'code' => $code, 'name' => $name, 'category' => $cat,
            'price' => $price, 'bom' => $bom, 'popularity' => $popularity,
            'retail' => false,
        ];
    }

    /** Helper pembuat produk retail (non-BOM). */
    private static function r(string $code, string $name, string $cat, int $price, int $popularity): array
    {
        return [
            'code' => $code, 'name' => $name, 'category' => $cat,
            'price' => $price, 'bom' => [], 'popularity' => $popularity,
            'retail' => true,
        ];
    }

    /** Modifier group: name => [selection, required, options => [name, extra_price, bom?]]. */
    public static function modifierGroups(): array
    {
        return [
            'Sugar Level' => ['single', false, [
                ['Normal Sugar', 0], ['Less Sugar', 0], ['Half Sugar', 0], ['No Sugar', 0],
            ]],
            'Ice Level' => ['single', false, [
                ['Normal Ice', 0], ['Less Ice', 0], ['No Ice', 0],
            ]],
            'Pilihan Susu' => ['single', false, [
                ['Fresh Milk', 0], ['Oat Milk', 10000, 'MILK-OAT', 180], ['Almond Milk', 10000, 'MILK-ALM', 180],
            ]],
            'Extra Topping' => ['multiple', false, [
                ['Extra Espresso Shot', 8000, 'BEAN-HOUSE', 18],
                ['Whipped Cream', 6000, 'CREAM-WHIP', 30],
                ['Caramel Drizzle', 5000, 'SYR-CAR', 15],
                ['Vanilla Syrup', 5000, 'SYR-VAN', 15],
            ]],
        ];
    }

    /** @return array<int,string> */
    public static function firstNames(): array
    {
        return [
            'Budi', 'Siti', 'Agus', 'Dewi', 'Eko', 'Rini', 'Hendra', 'Nurul', 'Reza', 'Maya',
            'Fajar', 'Dian', 'Bambang', 'Putri', 'Ahmad', 'Ratna', 'Dimas', 'Sri', 'Bayu', 'Mega',
            'Aditya', 'Fitri', 'Rudi', 'Wulan', 'Gilang', 'Intan', 'Doni', 'Lestari', 'Yoga', 'Anisa',
            'Rian', 'Nadia', 'Teguh', 'Ayu', 'Aris', 'Tiara', 'Wahyu', 'Sari', 'Indra', 'Desi',
            'Farhan', 'Rika', 'Surya', 'Tari', 'Bagas', 'Hani', 'Lukman', 'Bella', 'Yusuf', 'Melati',
        ];
    }

    /** @return array<int,string> */
    public static function lastNames(): array
    {
        return [
            'Pratama', 'Santoso', 'Wijaya', 'Kusuma', 'Saputra', 'Hidayat', 'Nugroho', 'Wibowo',
            'Lestari', 'Purnomo', 'Setiawan', 'Hermawan', 'Susanto', 'Hartono', 'Gunawan', 'Suryono',
        ];
    }

    /** @return array<int,string> */
    public static function tableAreas(): array
    {
        return ['Indoor', 'Window', 'Outdoor', 'Bar', 'Mezzanine', 'Terrace'];
    }
}
