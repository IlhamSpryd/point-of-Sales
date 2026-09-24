<?php

namespace Database\Seeders\Pos;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductIngredientSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Map ingredient codes to IDs (ING-001 = id 1, ING-002 = id 2, etc.)
        // Build recipes: product_id => [[ingredient_id, quantity_required], ...]
        // Products are numbered 1-133 matching ProductSeeder order.
        // Ingredients are numbered 1-65 matching IngredientSeeder order.

        // Ingredient ID shortcuts based on IngredientSeeder order:
        // 1=Espresso Beans, 2=Arabica Gayo, 3=Robusta Lampung, 4=Decaf, 5=Susu UHT, 6=Oat Milk, 7=Almond Milk
        // 8=Santan, 9=Gula Aren, 10=Gula Pasir, 11=Sirup Vanilla, 12=Sirup Hazelnut, 13=Sirup Caramel
        // 14=Sirup Butterscotch, 15=Matcha, 16=Cokelat Bubuk, 17=Teh Hitam, 18=Teh Melati, 19=Earl Grey
        // 20=Chamomile, 21=Houjicha, 22=Whipped Cream, 23=Boba, 24=Ayam, 25=Daging Sapi, 26=Ikan Dori
        // 27=Salmon, 28=Udang, 29=Cumi, 30=Telur, 31=Tahu, 32=Beras, 33=Mie Telur, 34=Spaghetti
        // 35=Kwetiau, 36=Tepung Terigu, 37=Tepung Panir, 38=Roti Brioche, 39=Roti Tawar, 40=Tortilla Chips
        // 41=Kentang, 42=Keju Cheddar, 43=Mozzarella, 44=Mentega, 45=Mascarpone, 46=Bawang Putih
        // 47=Bawang Merah, 48=Bawang Bombai, 49=Cabai Merah, 50=Cabai Rawit, 51=Tomat
        // 52=Lettuce, 53=Timun, 54=Daun Mint, 55=Saus Tomat, 56=Kecap Manis, 57=Olive Oil
        // 58=Minyak Goreng, 59=Saus Teriyaki, 60=Bumbu Rendang, 61=Pisang, 62=Lemon, 63=Jeruk Nipis
        // 64=Stroberi, 65=Mangga

        $recipes = [
            // Kopi Signature (1-15) - all espresso-based
            1 => [[1, 18], [5, 200], [9, 30]],           // Yovel Signature Latte
            2 => [[1, 18], [5, 200], [13, 20]],          // Caramel Macchiato
            3 => [[1, 18], [12, 15], [5, 100]],          // Hazelnut Affogato
            4 => [[1, 18], [14, 25], [22, 30]],          // Butterscotch Coffee
            5 => [[1, 18], [5, 200]],                    // Pandan Latte
            6 => [[1, 18], [5, 200], [9, 25]],           // Gula Aren Latte
            7 => [[1, 18], [8, 100]],                    // Coconut Cold Brew
            8 => [[1, 18], [5, 200], [9, 20]],           // Klepon Latte
            9 => [[1, 18], [16, 15], [13, 20], [22, 30]], // Salted Caramel Mocha
            10 => [[1, 18], [45, 30], [16, 5]],           // Tiramisu Latte
            11 => [[1, 18], [5, 200], [11, 20]],          // Vanilla Bean Frappuccino
            12 => [[1, 18], [6, 200]],                    // Rose Latte
            13 => [[1, 18], [5, 200], [10, 10]],          // Cinnamon Dolce Latte
            14 => [[1, 18]],                              // Espresso Tonic
            15 => [[1, 18], [16, 20], [22, 30]],          // Black Forest Mocha

            // Kopi Klasik (16-27)
            16 => [[1, 18]],                              // Americano
            17 => [[1, 18], [5, 150]],                    // Cappuccino
            18 => [[1, 18], [5, 200]],                    // Cafe Latte
            19 => [[1, 18], [5, 150]],                    // Flat White
            20 => [[1, 9]],                               // Espresso Single
            21 => [[1, 18]],                              // Espresso Double
            22 => [[1, 18]],                              // Long Black
            23 => [[1, 18], [16, 15], [5, 200]],          // Mocha
            24 => [[3, 15], [10, 10]],                    // Kopi Tubruk
            25 => [[2, 18]],                              // V60 Pour Over
            26 => [[1, 25]],                              // Cold Brew Classic
            27 => [[1, 14], [5, 30]],                     // Macchiato

            // Non-Coffee (28-39)
            28 => [[16, 25], [5, 200]],                   // Chocolate Frappe
            29 => [[64, 80], [5, 100]],                   // Strawberry Smoothie
            30 => [[65, 100], [5, 100]],                  // Mango Smoothie
            31 => [[5, 200]],                             // Taro Latte
            32 => [[5, 200]],                             // Red Velvet Latte
            33 => [[5, 200], [22, 30]],                   // Oreo Milkshake
            34 => [[16, 25], [5, 200]],                   // Hot Chocolate
            35 => [[1, 9], [5, 100]],                     // Avocado Coffee (uses espresso)
            36 => [[61, 1], [5, 150]],                    // Banana Smoothie (1 buah pisang)
            37 => [[5, 200], [11, 20]],                   // Vanilla Milkshake
            38 => [[16, 20], [9, 20]],                    // Cokelat Aren
            39 => [[63, 1]],                              // Lychee Yakult

            // Teh & Matcha (40-51)
            40 => [[15, 5], [5, 200]],                    // Matcha Latte
            41 => [[21, 5], [5, 200]],                    // Houjicha Latte
            42 => [[17, 5], [62, 1]],                     // Lemon Tea
            43 => [[17, 5]],                              // Peach Tea
            44 => [[18, 5]],                              // Jasmine Tea
            45 => [[19, 5]],                              // Earl Grey Tea
            46 => [[20, 5]],                              // Chamomile Tea
            47 => [[17, 5], [5, 100]],                    // Thai Tea
            48 => [[15, 5], [5, 200], [22, 30]],          // Matcha Frappe
            49 => [[17, 5], [5, 100]],                    // Teh Tarik
            50 => [[5, 200]],                             // Butterfly Pea Latte
            51 => [[5, 200]],                             // Oolong Milk Tea

            // Mocktail & Soda (52-61)
            52 => [[65, 50]],                             // Sunset Paradise
            53 => [[62, 1], [54, 3]],                     // Blue Lagoon
            54 => [[63, 2], [54, 5]],                     // Virgin Mojito
            55 => [[62, 2]],                              // Lemon Squash
            56 => [],                                     // Passion Fruit Soda
            57 => [],                                     // Watermelon Crush
            58 => [[53, 50], [54, 3]],                    // Cucumber Mint Cooler
            59 => [[64, 40]],                             // Berry Punch
            60 => [],                                     // Yakult Soda Grape
            61 => [],                                     // Orange Fizz

            // Main Course Nusantara (62-76)
            62 => [[32, 200], [30, 1], [58, 20], [47, 10], [49, 5]], // Nasi Goreng Kampung
            63 => [[33, 200], [47, 10], [58, 20], [56, 15]],         // Mie Goreng Jawa
            64 => [[24, 200], [36, 30], [37, 30], [50, 10], [32, 200]], // Ayam Geprek
            65 => [[25, 200], [60, 50], [8, 100]],                   // Rendang Sapi
            66 => [[24, 150], [30, 1], [32, 200]],                   // Soto Ayam
            67 => [[24, 150], [32, 200]],                            // Nasi Campur Bali
            68 => [[26, 200], [8, 100]],                             // Gulai Ikan Patin
            69 => [[24, 250], [49, 15]],                             // Ayam Bakar Taliwang
            70 => [[25, 200], [32, 200]],                            // Rawon Daging
            71 => [[24, 200], [46, 10]],                             // Sate Ayam
            72 => [[32, 200], [24, 150], [8, 50]],                   // Nasi Uduk
            73 => [[32, 200], [58, 30]],                             // Pecel Lele (using generic)
            74 => [[25, 200], [36, 20]],                             // Bakso Urat
            75 => [[35, 200], [28, 50], [29, 50]],                   // Kwetiau Goreng Seafood
            76 => [[25, 300], [58, 20]],                             // Iga Bakar

            // Main Course Western (77-88)
            77 => [[24, 200], [44, 15]],                             // Chicken Steak
            78 => [[25, 200], [42, 20], [52, 10], [38, 1]],          // Beef Burger
            79 => [[26, 200], [36, 30], [41, 100]],                  // Fish & Chips
            80 => [[34, 150], [46, 10], [49, 3], [57, 15]],          // Aglio Olio
            81 => [[34, 150], [30, 2], [42, 20]],                    // Carbonara
            82 => [[34, 150], [25, 100], [51, 50], [55, 30]],        // Bolognese
            83 => [[24, 200], [37, 30], [36, 20], [32, 200]],        // Katsu Curry
            84 => [[27, 200], [62, 1], [44, 15]],                    // Salmon Grilled
            85 => [[25, 400], [41, 100]],                            // BBQ Ribs
            86 => [[24, 100], [30, 1], [39, 3], [52, 10]],           // Club Sandwich
            87 => [[32, 200], [44, 20], [42, 15]],                   // Mushroom Risotto
            88 => [[25, 150], [34, 100], [43, 30]],                  // Beef Lasagna

            // Rice Bowl (89-98)
            89 => [[24, 180], [59, 20], [32, 200]],                  // Chicken Teriyaki
            90 => [[25, 150], [59, 20], [32, 200]],                  // Beef Yakiniku
            91 => [[24, 180], [37, 30], [32, 200]],                  // Chicken Katsu
            92 => [[27, 150], [32, 200]],                            // Salmon Mentai
            93 => [[24, 180], [50, 10], [32, 200]],                  // Ayam Geprek RB
            94 => [[25, 150], [60, 40], [32, 200]],                  // Rendang RB
            95 => [[24, 100], [30, 2], [32, 200]],                   // Egg Chicken
            96 => [[26, 150], [32, 200]],                            // Dori Sambal Matah
            97 => [[31, 200], [59, 20], [32, 200]],                  // Tofu Teriyaki
            98 => [[24, 180], [32, 200]],                            // Chicken Blackpepper

            // Snack & Appetizer (99-113)
            99 => [[41, 200], [58, 50]],                            // French Fries
            100 => [[24, 300], [58, 50]],                            // Chicken Wings
            101 => [[48, 150], [36, 30], [58, 40]],                  // Onion Rings
            102 => [[40, 100], [42, 30]],                            // Nachos Supreme
            103 => [[24, 200], [36, 20], [37, 20]],                  // Chicken Nuggets
            104 => [[31, 200], [36, 20], [58, 30]],                  // Tahu Crispy
            105 => [[61, 2], [36, 20], [42, 15]],                    // Pisang Goreng Keju
            106 => [[36, 30], [58, 20]],                             // Spring Roll
            107 => [[43, 120], [36, 20], [58, 30]],                  // Mozzarella Sticks
            108 => [[39, 2], [46, 10], [44, 15]],                    // Garlic Bread
            109 => [[36, 20], [28, 30]],                             // Dimsum Mix
            110 => [[39, 2], [44, 10], [16, 10]],                    // Roti Bakar
            111 => [[41, 200], [58, 40]],                            // Kentang Wedges
            112 => [[36, 30], [58, 20]],                             // Cireng
            113 => [[29, 150], [36, 20], [58, 30]],                  // Calamari Rings

            // Dessert (114-125)
            114 => [[16, 30], [44, 20], [30, 2], [36, 30]],          // Brownies Lava
            115 => [[36, 50], [30, 2], [5, 50], [44, 15]],           // Pancake Stack
            116 => [[36, 50], [30, 1], [44, 15], [22, 30]],          // Waffle Classic
            117 => [[36, 40], [10, 20], [16, 15]],                   // Churros
            118 => [[30, 3], [5, 200], [10, 30]],                    // Crème Brûlée
            119 => [[61, 2], [5, 100], [16, 10]],                    // Banana Split
            120 => [[45, 50], [1, 9], [30, 2]],                      // Tiramisu (dessert)
            121 => [[61, 2]],                                         // Es Pisang Ijo
            122 => [[9, 20]],                                         // Es Cendol
            123 => [[42, 50], [30, 2], [64, 30]],                    // Cheese Cake
            124 => [[15, 3], [64, 20], [16, 10]],                    // Mochi Ice Cream
            125 => [[36, 30], [44, 15]],                             // Croffle Nutella

            // Add-on (126-133)
            126 => [[32, 150]],                                       // Extra Nasi
            127 => [[30, 1]],                                         // Telur Ceplok
            128 => [[42, 20]],                                        // Keju Cheddar Extra
            129 => [[49, 10]],                                        // Extra Sambal
            130 => [[41, 100], [58, 20]],                             // French Fries Side
            131 => [[52, 30], [53, 20], [51, 20]],                    // Side Salad
            132 => [[43, 30]],                                        // Mozzarella Topping
            133 => [[5, 80]],                                         // Ice Cream Scoop
        ];

        $rows = [];
        foreach ($recipes as $productId => $ingredients) {
            foreach ($ingredients as [$ingredientId, $qty]) {
                $rows[] = [
                    'product_id' => $productId,
                    'ingredient_id' => $ingredientId,
                    'quantity_required' => $qty,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('product_ingredients')->delete();
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('product_ingredients')->insert($chunk);
        }

        $this->command->info('  ✓ Product ingredients seeded: '.count($rows).' recipe links');
    }
}
