<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function index(): View
    {
        $settings = [
            'tax_rate' => Setting::get('tax_rate', config('pos.tax_rate', 0.11)),
            'rounding_value' => Setting::get('rounding_value', config('pos.rounding_value', 100)),
            'rounding_behavior' => Setting::get('rounding_behavior', config('pos.rounding_behavior', 'ROUND_NEAREST')),
            'store_name' => Setting::get('store_name', config('app.name', 'Apeiron POS')),
            'store_address' => Setting::get('store_address', ''),
            'store_phone' => Setting::get('store_phone', ''),
            'store_email' => Setting::get('store_email', ''),
        ];

        return view('settings.index', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'rounding_value' => ['nullable', 'integer', 'min:1'],
            'rounding_behavior' => ['nullable', 'string', 'in:ROUND_NEAREST,ROUND_UP,ROUND_DOWN'],
            'store_name' => ['nullable', 'string', 'max:255'],
            'store_address' => ['nullable', 'string', 'max:500'],
            'store_phone' => ['nullable', 'string', 'max:50'],
            'store_email' => ['nullable', 'email', 'max:255'],
        ]);

        foreach ($validated as $key => $value) {
            if ($value !== null) {
                $type = is_numeric($value) ? (is_int($value) ? 'integer' : 'float') : 'string';
                Setting::set($key, $value, $type);
            }
        }

        return redirect()->route('settings.index')->with('success', 'Pengaturan berhasil diperbarui.');
    }
}
