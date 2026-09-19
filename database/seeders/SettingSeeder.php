<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * SettingSeeder: Memindahkan nilai default konfigurasi bisnis dari
 * config/pos.php ke dalam tabel settings, supaya Administrator bisa
 * mengubahnya nanti lewat halaman Pengaturan Sistem tanpa perlu akses
 * server atau redeploy kode.
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['key' => 'tax_rate', 'value' => '0.11', 'type' => 'string', 'description' => 'Tarif pajak PB1 (contoh: 0.11 = 11%)'],
            ['key' => 'service_charge_rate', 'value' => '0', 'type' => 'string', 'description' => 'Tarif biaya layanan (contoh: 0.05 = 5%)'],
            ['key' => 'rounding_behavior', 'value' => 'ROUND_NEAREST', 'type' => 'string', 'description' => 'Perilaku pembulatan total transaksi'],
            ['key' => 'rounding_value', 'value' => '100', 'type' => 'integer', 'description' => 'Kelipatan pembulatan dalam Rupiah'],
            ['key' => 'receipt_width_mm', 'value' => '58', 'type' => 'integer', 'description' => 'Lebar kertas struk thermal (mm)'],
        ];

        foreach ($defaults as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
