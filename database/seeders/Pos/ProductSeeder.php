<?php

namespace Database\Seeders\Pos;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Category IDs match the order from CategorySeeder (1–11)
        // 1=Kopi Signature, 2=Kopi Klasik, 3=Non-Coffee, 4=Teh & Matcha, 5=Mocktail & Soda
        // 6=Main Course Nusantara, 7=Main Course Western, 8=Rice Bowl, 9=Snack & Appetizer
        // 10=Dessert, 11=Add-on & Topping

        $products = [
            // === Kopi Signature (cat 1) — 15 items ===
            ['code' => 'KSN-SIG-001', 'cat' => 1, 'name' => 'Yovel Signature Latte', 'price' => 38000, 'desc' => 'Espresso blend house dengan susu creamy dan sentuhan gula aren'],
            ['code' => 'KSN-SIG-002', 'cat' => 1, 'name' => 'Caramel Macchiato Yovel', 'price' => 42000, 'desc' => 'Espresso doppio, steamed milk, caramel drizzle'],
            ['code' => 'KSN-SIG-003', 'cat' => 1, 'name' => 'Hazelnut Affogato', 'price' => 45000, 'desc' => 'Gelato vanilla disiram espresso hazelnut'],
            ['code' => 'KSN-SIG-004', 'cat' => 1, 'name' => 'Butterscotch Coffee', 'price' => 40000, 'desc' => 'Cold brew dengan sirup butterscotch dan cream float'],
            ['code' => 'KSN-SIG-005', 'cat' => 1, 'name' => 'Pandan Latte', 'price' => 38000, 'desc' => 'Espresso bertemu aroma pandan khas nusantara'],
            ['code' => 'KSN-SIG-006', 'cat' => 1, 'name' => 'Gula Aren Latte', 'price' => 35000, 'desc' => 'Espresso dengan gula aren asli dan susu segar'],
            ['code' => 'KSN-SIG-007', 'cat' => 1, 'name' => 'Coconut Cold Brew', 'price' => 40000, 'desc' => 'Cold brew 18 jam dengan santan kelapa muda'],
            ['code' => 'KSN-SIG-008', 'cat' => 1, 'name' => 'Klepon Latte', 'price' => 42000, 'desc' => 'Espresso, pandan, gula merah — rasa klepon dalam latte'],
            ['code' => 'KSN-SIG-009', 'cat' => 1, 'name' => 'Salted Caramel Mocha', 'price' => 44000, 'desc' => 'Mocha dengan salted caramel dan whipped cream'],
            ['code' => 'KSN-SIG-010', 'cat' => 1, 'name' => 'Tiramisu Latte', 'price' => 43000, 'desc' => 'Espresso, mascarpone cream, cocoa dusting'],
            ['code' => 'KSN-SIG-011', 'cat' => 1, 'name' => 'Vanilla Bean Frappuccino', 'price' => 42000, 'desc' => 'Blended vanilla bean ice dengan espresso shot'],
            ['code' => 'KSN-SIG-012', 'cat' => 1, 'name' => 'Rose Latte', 'price' => 40000, 'desc' => 'Espresso dengan sirup rose dan susu oat'],
            ['code' => 'KSN-SIG-013', 'cat' => 1, 'name' => 'Cinnamon Dolce Latte', 'price' => 39000, 'desc' => 'Latte hangat dengan sirup kayu manis'],
            ['code' => 'KSN-SIG-014', 'cat' => 1, 'name' => 'Espresso Tonic', 'price' => 38000, 'desc' => 'Double shot espresso di atas tonic water segar'],
            ['code' => 'KSN-SIG-015', 'cat' => 1, 'name' => 'Black Forest Mocha', 'price' => 45000, 'desc' => 'Mocha cherry, dark chocolate, whipped cream'],

            // === Kopi Klasik (cat 2) — 12 items ===
            ['code' => 'KSN-KLS-001', 'cat' => 2, 'name' => 'Americano', 'price' => 25000, 'desc' => 'Double shot espresso + hot water'],
            ['code' => 'KSN-KLS-002', 'cat' => 2, 'name' => 'Cappuccino', 'price' => 32000, 'desc' => 'Espresso, steamed milk, foam tebal'],
            ['code' => 'KSN-KLS-003', 'cat' => 2, 'name' => 'Cafe Latte', 'price' => 32000, 'desc' => 'Espresso dengan susu steamed creamy'],
            ['code' => 'KSN-KLS-004', 'cat' => 2, 'name' => 'Flat White', 'price' => 33000, 'desc' => 'Ristretto double dengan micro-foam velvet'],
            ['code' => 'KSN-KLS-005', 'cat' => 2, 'name' => 'Espresso Single', 'price' => 18000, 'desc' => 'Single shot espresso murni'],
            ['code' => 'KSN-KLS-006', 'cat' => 2, 'name' => 'Espresso Double', 'price' => 22000, 'desc' => 'Doppio espresso — bold & intense'],
            ['code' => 'KSN-KLS-007', 'cat' => 2, 'name' => 'Long Black', 'price' => 26000, 'desc' => 'Hot water + double ristretto di atas'],
            ['code' => 'KSN-KLS-008', 'cat' => 2, 'name' => 'Mocha', 'price' => 35000, 'desc' => 'Espresso, cokelat Belgia, steamed milk'],
            ['code' => 'KSN-KLS-009', 'cat' => 2, 'name' => 'Kopi Tubruk', 'price' => 15000, 'desc' => 'Kopi bubuk tubruk tradisional'],
            ['code' => 'KSN-KLS-010', 'cat' => 2, 'name' => 'V60 Pour Over', 'price' => 35000, 'desc' => 'Manual brew single origin Arabica Gayo'],
            ['code' => 'KSN-KLS-011', 'cat' => 2, 'name' => 'Cold Brew Classic', 'price' => 30000, 'desc' => 'Cold brew 18 jam tanpa campuran'],
            ['code' => 'KSN-KLS-012', 'cat' => 2, 'name' => 'Macchiato', 'price' => 28000, 'desc' => 'Espresso dengan sedikit foam di atas'],

            // === Non-Coffee (cat 3) — 12 items ===
            ['code' => 'KSN-NCF-001', 'cat' => 3, 'name' => 'Chocolate Frappe', 'price' => 35000, 'desc' => 'Blended dark chocolate ice premium'],
            ['code' => 'KSN-NCF-002', 'cat' => 3, 'name' => 'Strawberry Smoothie', 'price' => 33000, 'desc' => 'Smoothie stroberi segar dengan yoghurt'],
            ['code' => 'KSN-NCF-003', 'cat' => 3, 'name' => 'Mango Smoothie', 'price' => 33000, 'desc' => 'Smoothie mangga harum manis'],
            ['code' => 'KSN-NCF-004', 'cat' => 3, 'name' => 'Taro Latte', 'price' => 30000, 'desc' => 'Taro ungu creamy tanpa kafein'],
            ['code' => 'KSN-NCF-005', 'cat' => 3, 'name' => 'Red Velvet Latte', 'price' => 32000, 'desc' => 'Cream cheese red velvet latte'],
            ['code' => 'KSN-NCF-006', 'cat' => 3, 'name' => 'Oreo Milkshake', 'price' => 35000, 'desc' => 'Milkshake Oreo dengan whipped cream'],
            ['code' => 'KSN-NCF-007', 'cat' => 3, 'name' => 'Hot Chocolate', 'price' => 30000, 'desc' => 'Cokelat panas Belgian dengan marshmallow'],
            ['code' => 'KSN-NCF-008', 'cat' => 3, 'name' => 'Avocado Coffee', 'price' => 35000, 'desc' => 'Alpukat blend dengan espresso shot — unik'],
            ['code' => 'KSN-NCF-009', 'cat' => 3, 'name' => 'Banana Smoothie', 'price' => 30000, 'desc' => 'Smoothie pisang susu madu'],
            ['code' => 'KSN-NCF-010', 'cat' => 3, 'name' => 'Vanilla Milkshake', 'price' => 32000, 'desc' => 'Milkshake vanilla premium'],
            ['code' => 'KSN-NCF-011', 'cat' => 3, 'name' => 'Cokelat Aren', 'price' => 28000, 'desc' => 'Cokelat premium dengan pemanis gula aren'],
            ['code' => 'KSN-NCF-012', 'cat' => 3, 'name' => 'Lychee Yakult', 'price' => 28000, 'desc' => 'Yakult segar dengan leci dan soda'],

            // === Teh & Matcha (cat 4) — 12 items ===
            ['code' => 'KSN-TEA-001', 'cat' => 4, 'name' => 'Matcha Latte', 'price' => 35000, 'desc' => 'Matcha Uji grade-A dengan susu segar'],
            ['code' => 'KSN-TEA-002', 'cat' => 4, 'name' => 'Houjicha Latte', 'price' => 35000, 'desc' => 'Teh panggang Jepang latte'],
            ['code' => 'KSN-TEA-003', 'cat' => 4, 'name' => 'Lemon Tea', 'price' => 22000, 'desc' => 'Teh hitam dengan perasan lemon segar'],
            ['code' => 'KSN-TEA-004', 'cat' => 4, 'name' => 'Peach Tea', 'price' => 25000, 'desc' => 'Teh persik harum menyegarkan'],
            ['code' => 'KSN-TEA-005', 'cat' => 4, 'name' => 'Jasmine Tea', 'price' => 20000, 'desc' => 'Teh melati premium tanpa gula'],
            ['code' => 'KSN-TEA-006', 'cat' => 4, 'name' => 'Earl Grey Tea', 'price' => 22000, 'desc' => 'Teh Earl Grey bergamot aroma khas'],
            ['code' => 'KSN-TEA-007', 'cat' => 4, 'name' => 'Chamomile Tea', 'price' => 25000, 'desc' => 'Teh chamomile relaksasi'],
            ['code' => 'KSN-TEA-008', 'cat' => 4, 'name' => 'Thai Tea', 'price' => 28000, 'desc' => 'Thai tea susu manis khas Bangkok'],
            ['code' => 'KSN-TEA-009', 'cat' => 4, 'name' => 'Matcha Frappe', 'price' => 38000, 'desc' => 'Blended matcha ice dengan whipped cream'],
            ['code' => 'KSN-TEA-010', 'cat' => 4, 'name' => 'Teh Tarik', 'price' => 20000, 'desc' => 'Teh tarik ala mamak — creamy berbuih'],
            ['code' => 'KSN-TEA-011', 'cat' => 4, 'name' => 'Butterfly Pea Latte', 'price' => 30000, 'desc' => 'Bunga telang biru dengan susu lemon — berubah warna'],
            ['code' => 'KSN-TEA-012', 'cat' => 4, 'name' => 'Oolong Milk Tea', 'price' => 28000, 'desc' => 'Oolong premium dengan susu creamy'],

            // === Mocktail & Soda (cat 5) — 10 items ===
            ['code' => 'KSN-MCK-001', 'cat' => 5, 'name' => 'Sunset Paradise', 'price' => 32000, 'desc' => 'Mocktail mangga, grenadine, soda — warna sunset'],
            ['code' => 'KSN-MCK-002', 'cat' => 5, 'name' => 'Blue Lagoon', 'price' => 30000, 'desc' => 'Blue curacao syrup, lemon, soda, mint'],
            ['code' => 'KSN-MCK-003', 'cat' => 5, 'name' => 'Virgin Mojito', 'price' => 30000, 'desc' => 'Lime, mint, soda — segar tanpa alkohol'],
            ['code' => 'KSN-MCK-004', 'cat' => 5, 'name' => 'Lemon Squash', 'price' => 25000, 'desc' => 'Perasan lemon segar dengan soda'],
            ['code' => 'KSN-MCK-005', 'cat' => 5, 'name' => 'Passion Fruit Soda', 'price' => 28000, 'desc' => 'Markisa asli dengan sparkling water'],
            ['code' => 'KSN-MCK-006', 'cat' => 5, 'name' => 'Watermelon Crush', 'price' => 25000, 'desc' => 'Semangka segar di-blend dengan es'],
            ['code' => 'KSN-MCK-007', 'cat' => 5, 'name' => 'Cucumber Mint Cooler', 'price' => 25000, 'desc' => 'Timun, mint, madu, air jeruk nipis'],
            ['code' => 'KSN-MCK-008', 'cat' => 5, 'name' => 'Berry Punch', 'price' => 32000, 'desc' => 'Mixed berries dengan cranberry soda'],
            ['code' => 'KSN-MCK-009', 'cat' => 5, 'name' => 'Yakult Soda Grape', 'price' => 25000, 'desc' => 'Yakult, anggur, soda segar'],
            ['code' => 'KSN-MCK-010', 'cat' => 5, 'name' => 'Orange Fizz', 'price' => 22000, 'desc' => 'Jus jeruk segar dengan sparkling water'],

            // === Main Course Nusantara (cat 6) — 15 items ===
            ['code' => 'KSN-NUS-001', 'cat' => 6, 'name' => 'Nasi Goreng Kampung', 'price' => 45000, 'desc' => 'Nasi goreng bumbu kampung, telur ceplok, kerupuk'],
            ['code' => 'KSN-NUS-002', 'cat' => 6, 'name' => 'Mie Goreng Jawa', 'price' => 42000, 'desc' => 'Mie goreng manis khas Jawa dengan sayuran'],
            ['code' => 'KSN-NUS-003', 'cat' => 6, 'name' => 'Ayam Geprek Sambal Matah', 'price' => 48000, 'desc' => 'Ayam crispy geprek dengan sambal matah Bali'],
            ['code' => 'KSN-NUS-004', 'cat' => 6, 'name' => 'Rendang Sapi', 'price' => 65000, 'desc' => 'Rendang daging sapi empuk khas Padang'],
            ['code' => 'KSN-NUS-005', 'cat' => 6, 'name' => 'Soto Ayam Lamongan', 'price' => 42000, 'desc' => 'Soto kuning dengan ayam suwir dan telur'],
            ['code' => 'KSN-NUS-006', 'cat' => 6, 'name' => 'Nasi Campur Bali', 'price' => 55000, 'desc' => 'Nasi campur khas Bali — ayam betutu, sate lilit, lawar'],
            ['code' => 'KSN-NUS-007', 'cat' => 6, 'name' => 'Gulai Ikan Patin', 'price' => 55000, 'desc' => 'Gulai kuning ikan patin bumbu rempah'],
            ['code' => 'KSN-NUS-008', 'cat' => 6, 'name' => 'Ayam Bakar Taliwang', 'price' => 52000, 'desc' => 'Ayam bakar khas Lombok pedas'],
            ['code' => 'KSN-NUS-009', 'cat' => 6, 'name' => 'Rawon Daging Sapi', 'price' => 58000, 'desc' => 'Sup rawon hitam Surabaya dengan tauge, telur asin'],
            ['code' => 'KSN-NUS-010', 'cat' => 6, 'name' => 'Sate Ayam Madura', 'price' => 45000, 'desc' => '10 tusuk sate ayam bumbu kacang khas Madura'],
            ['code' => 'KSN-NUS-011', 'cat' => 6, 'name' => 'Nasi Uduk Betawi', 'price' => 40000, 'desc' => 'Nasi uduk komplit dengan lauk ayam goreng, sambal kacang'],
            ['code' => 'KSN-NUS-012', 'cat' => 6, 'name' => 'Pecel Lele Lalapan', 'price' => 38000, 'desc' => 'Lele goreng crispy, sambal terasi, lalapan segar'],
            ['code' => 'KSN-NUS-013', 'cat' => 6, 'name' => 'Bakso Urat Jumbo', 'price' => 40000, 'desc' => 'Bakso urat sapi jumbo kuah kaldu'],
            ['code' => 'KSN-NUS-014', 'cat' => 6, 'name' => 'Kwetiau Goreng Seafood', 'price' => 48000, 'desc' => 'Kwetiau goreng udang, cumi, sayuran'],
            ['code' => 'KSN-NUS-015', 'cat' => 6, 'name' => 'Iga Bakar Madu', 'price' => 75000, 'desc' => 'Iga sapi bakar madu empuk fall-off-the-bone'],

            // === Main Course Western (cat 7) — 12 items ===
            ['code' => 'KSN-WST-001', 'cat' => 7, 'name' => 'Chicken Steak', 'price' => 55000, 'desc' => 'Grilled chicken breast dengan mushroom sauce'],
            ['code' => 'KSN-WST-002', 'cat' => 7, 'name' => 'Beef Burger Classic', 'price' => 58000, 'desc' => 'Beef patty 200g, cheddar, lettuce, brioche bun'],
            ['code' => 'KSN-WST-003', 'cat' => 7, 'name' => 'Fish & Chips', 'price' => 52000, 'desc' => 'Dory fillet crispy dengan tartar sauce dan fries'],
            ['code' => 'KSN-WST-004', 'cat' => 7, 'name' => 'Spaghetti Aglio Olio', 'price' => 48000, 'desc' => 'Spaghetti bawang putih, cabai, olive oil, parsley'],
            ['code' => 'KSN-WST-005', 'cat' => 7, 'name' => 'Spaghetti Carbonara', 'price' => 52000, 'desc' => 'Carbonara klasik — egg yolk, pancetta, parmesan'],
            ['code' => 'KSN-WST-006', 'cat' => 7, 'name' => 'Spaghetti Bolognese', 'price' => 50000, 'desc' => 'Ragù daging sapi slow-cook dengan saus tomat'],
            ['code' => 'KSN-WST-007', 'cat' => 7, 'name' => 'Chicken Katsu Curry', 'price' => 55000, 'desc' => 'Katsu ayam crispy dengan Japanese curry sauce'],
            ['code' => 'KSN-WST-008', 'cat' => 7, 'name' => 'Salmon Grilled', 'price' => 85000, 'desc' => 'Norwegian salmon grilled dengan lemon butter sauce'],
            ['code' => 'KSN-WST-009', 'cat' => 7, 'name' => 'BBQ Ribs Half Rack', 'price' => 95000, 'desc' => 'Pork-free beef ribs BBQ dengan coleslaw & fries'],
            ['code' => 'KSN-WST-010', 'cat' => 7, 'name' => 'Club Sandwich', 'price' => 45000, 'desc' => 'Triple decker — ayam, telur, bacon beef, sayuran'],
            ['code' => 'KSN-WST-011', 'cat' => 7, 'name' => 'Mushroom Risotto', 'price' => 55000, 'desc' => 'Risotto jamur truffle dengan parmesan'],
            ['code' => 'KSN-WST-012', 'cat' => 7, 'name' => 'Beef Lasagna', 'price' => 58000, 'desc' => 'Lasagna berlapis daging sapi, béchamel, mozzarella'],

            // === Rice Bowl (cat 8) — 10 items ===
            ['code' => 'KSN-RCB-001', 'cat' => 8, 'name' => 'Rice Bowl Chicken Teriyaki', 'price' => 38000, 'desc' => 'Ayam teriyaki di atas nasi Jepang'],
            ['code' => 'KSN-RCB-002', 'cat' => 8, 'name' => 'Rice Bowl Beef Yakiniku', 'price' => 48000, 'desc' => 'Irisan beef yakiniku manis di atas nasi'],
            ['code' => 'KSN-RCB-003', 'cat' => 8, 'name' => 'Rice Bowl Chicken Katsu', 'price' => 40000, 'desc' => 'Katsu ayam crispy dengan saus mayo pedas'],
            ['code' => 'KSN-RCB-004', 'cat' => 8, 'name' => 'Rice Bowl Salmon Mentai', 'price' => 55000, 'desc' => 'Salmon panggang saus mentai di atas nasi'],
            ['code' => 'KSN-RCB-005', 'cat' => 8, 'name' => 'Rice Bowl Ayam Geprek', 'price' => 35000, 'desc' => 'Ayam geprek sambal ijo di atas nasi hangat'],
            ['code' => 'KSN-RCB-006', 'cat' => 8, 'name' => 'Rice Bowl Rendang', 'price' => 45000, 'desc' => 'Rendang sapi kering dalam rice bowl modern'],
            ['code' => 'KSN-RCB-007', 'cat' => 8, 'name' => 'Rice Bowl Egg Chicken', 'price' => 35000, 'desc' => 'Ayam suwir telur orak-arik di atas nasi'],
            ['code' => 'KSN-RCB-008', 'cat' => 8, 'name' => 'Rice Bowl Dori Sambal Matah', 'price' => 40000, 'desc' => 'Ikan dori goreng tepung dengan sambal matah'],
            ['code' => 'KSN-RCB-009', 'cat' => 8, 'name' => 'Rice Bowl Tofu Teriyaki', 'price' => 32000, 'desc' => 'Tahu crispy teriyaki — pilihan vegetarian'],
            ['code' => 'KSN-RCB-010', 'cat' => 8, 'name' => 'Rice Bowl Chicken Blackpepper', 'price' => 40000, 'desc' => 'Ayam saus blackpepper pedas manis'],

            // === Snack & Appetizer (cat 9) — 15 items ===
            ['code' => 'KSN-SNK-001', 'cat' => 9, 'name' => 'French Fries', 'price' => 25000, 'desc' => 'Kentang goreng crispy tabur bumbu pilihan'],
            ['code' => 'KSN-SNK-002', 'cat' => 9, 'name' => 'Chicken Wings (6 pcs)', 'price' => 42000, 'desc' => 'Sayap ayam goreng — BBQ / Spicy / Honey Garlic'],
            ['code' => 'KSN-SNK-003', 'cat' => 9, 'name' => 'Onion Rings', 'price' => 25000, 'desc' => 'Bawang bombai crispy ring'],
            ['code' => 'KSN-SNK-004', 'cat' => 9, 'name' => 'Nachos Supreme', 'price' => 38000, 'desc' => 'Tortilla chips, salsa, cheese sauce, jalapeño'],
            ['code' => 'KSN-SNK-005', 'cat' => 9, 'name' => 'Chicken Nuggets (8 pcs)', 'price' => 30000, 'desc' => 'Nugget ayam homemade dengan saus pilihan'],
            ['code' => 'KSN-SNK-006', 'cat' => 9, 'name' => 'Tahu Crispy', 'price' => 20000, 'desc' => 'Tahu goreng tepung garing dengan sambal kecap'],
            ['code' => 'KSN-SNK-007', 'cat' => 9, 'name' => 'Pisang Goreng Keju', 'price' => 22000, 'desc' => 'Pisang goreng tabur keju cheddar dan susu kental'],
            ['code' => 'KSN-SNK-008', 'cat' => 9, 'name' => 'Spring Roll (4 pcs)', 'price' => 28000, 'desc' => 'Lumpia sayur goreng crispy dengan sweet chili'],
            ['code' => 'KSN-SNK-009', 'cat' => 9, 'name' => 'Mozzarella Sticks', 'price' => 35000, 'desc' => '6 pcs mozzarella goreng stretchy dengan marinara'],
            ['code' => 'KSN-SNK-010', 'cat' => 9, 'name' => 'Garlic Bread', 'price' => 22000, 'desc' => 'Roti bawang putih panggang dengan butter'],
            ['code' => 'KSN-SNK-011', 'cat' => 9, 'name' => 'Dimsum Mix (6 pcs)', 'price' => 35000, 'desc' => 'Siomay, hakau, lumpia udang kukus'],
            ['code' => 'KSN-SNK-012', 'cat' => 9, 'name' => 'Roti Bakar', 'price' => 20000, 'desc' => 'Roti bakar dengan selai cokelat-keju'],
            ['code' => 'KSN-SNK-013', 'cat' => 9, 'name' => 'Kentang Wedges', 'price' => 28000, 'desc' => 'Potato wedges seasoned dengan sour cream dip'],
            ['code' => 'KSN-SNK-014', 'cat' => 9, 'name' => 'Cireng Bumbu Rujak', 'price' => 18000, 'desc' => 'Cireng goreng dengan bumbu rujak pedas manis'],
            ['code' => 'KSN-SNK-015', 'cat' => 9, 'name' => 'Calamari Rings', 'price' => 38000, 'desc' => 'Cumi goreng tepung dengan tartar sauce'],

            // === Dessert (cat 10) — 12 items ===
            ['code' => 'KSN-DST-001', 'cat' => 10, 'name' => 'Brownies Lava Cake', 'price' => 35000, 'desc' => 'Brownies lumer dark chocolate dengan vanilla ice cream'],
            ['code' => 'KSN-DST-002', 'cat' => 10, 'name' => 'Pancake Stack', 'price' => 38000, 'desc' => 'Pancake tumpuk 3 dengan maple syrup dan berry'],
            ['code' => 'KSN-DST-003', 'cat' => 10, 'name' => 'Waffle Classic', 'price' => 35000, 'desc' => 'Belgian waffle dengan whipped cream dan buah'],
            ['code' => 'KSN-DST-004', 'cat' => 10, 'name' => 'Churros', 'price' => 30000, 'desc' => 'Churros crispy dengan chocolate dipping sauce'],
            ['code' => 'KSN-DST-005', 'cat' => 10, 'name' => 'Crème Brûlée', 'price' => 38000, 'desc' => 'Custard vanilla dengan karamel torch di atas'],
            ['code' => 'KSN-DST-006', 'cat' => 10, 'name' => 'Banana Split', 'price' => 40000, 'desc' => '3 scoop ice cream, pisang, saus cokelat, cherry'],
            ['code' => 'KSN-DST-007', 'cat' => 10, 'name' => 'Tiramisu', 'price' => 42000, 'desc' => 'Tiramisu klasik — ladyfinger, mascarpone, espresso'],
            ['code' => 'KSN-DST-008', 'cat' => 10, 'name' => 'Es Pisang Ijo', 'price' => 25000, 'desc' => 'Pisang balut tepung hijau pandan dengan sirup merah'],
            ['code' => 'KSN-DST-009', 'cat' => 10, 'name' => 'Es Cendol', 'price' => 22000, 'desc' => 'Cendol pandan santan gula merah asli'],
            ['code' => 'KSN-DST-010', 'cat' => 10, 'name' => 'Cheese Cake Slice', 'price' => 42000, 'desc' => 'New York cheesecake dengan berry compote'],
            ['code' => 'KSN-DST-011', 'cat' => 10, 'name' => 'Mochi Ice Cream (3 pcs)', 'price' => 30000, 'desc' => 'Mochi isi es krim — matcha, stroberi, cokelat'],
            ['code' => 'KSN-DST-012', 'cat' => 10, 'name' => 'Croffle Nutella', 'price' => 32000, 'desc' => 'Croissant waffle dengan Nutella dan buah segar'],

            // === Add-on & Topping (cat 11) — 8 items ===
            ['code' => 'KSN-ADD-001', 'cat' => 11, 'name' => 'Extra Nasi Putih', 'price' => 8000, 'desc' => 'Tambahan porsi nasi putih hangat'],
            ['code' => 'KSN-ADD-002', 'cat' => 11, 'name' => 'Telur Ceplok', 'price' => 7000, 'desc' => 'Telur mata sapi tambahan'],
            ['code' => 'KSN-ADD-003', 'cat' => 11, 'name' => 'Keju Cheddar Extra', 'price' => 8000, 'desc' => 'Tambahan keju cheddar parut'],
            ['code' => 'KSN-ADD-004', 'cat' => 11, 'name' => 'Extra Sambal', 'price' => 5000, 'desc' => 'Sambal terasi/matah/ijo ekstra'],
            ['code' => 'KSN-ADD-005', 'cat' => 11, 'name' => 'French Fries Side', 'price' => 15000, 'desc' => 'Porsi kecil french fries sebagai pendamping'],
            ['code' => 'KSN-ADD-006', 'cat' => 11, 'name' => 'Side Salad', 'price' => 12000, 'desc' => 'Salad segar dengan vinaigrette dressing'],
            ['code' => 'KSN-ADD-007', 'cat' => 11, 'name' => 'Mozzarella Topping', 'price' => 10000, 'desc' => 'Taburan mozzarella melted'],
            ['code' => 'KSN-ADD-008', 'cat' => 11, 'name' => 'Ice Cream Scoop', 'price' => 15000, 'desc' => '1 scoop ice cream vanilla/cokelat/stroberi'],
        ];

        $rows = [];
        foreach ($products as $p) {
            $rows[] = [
                'product_code' => $p['code'],
                'category_id' => $p['cat'],
                'product_name' => $p['name'],
                'product_price' => $p['price'],
                'product_description' => $p['desc'],
                'stock' => rand(50, 500),
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('products')->delete();
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('products')->insert($chunk);
        }

        $this->command->info('  ✓ Products seeded: '.count($rows));
    }
}
