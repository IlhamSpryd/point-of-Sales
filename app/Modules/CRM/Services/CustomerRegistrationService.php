<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerLoyaltyAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CustomerRegistrationService — Layanan pendaftaran pelanggan, normalisasi nomor telepon, dan walk-in handling.
 *
 * Mengikuti spesifikasi:
 * - Normalisasi nomor telepon format E.164 (+62 untuk Indonesia).
 * - Penegakan phone_uniqueness_key virtual column unik per tenant untuk pelanggan aktif (T-CRM-01).
 * - Penanganan pelanggan anonim walk-in.
 */
class CustomerRegistrationService
{
    /**
     * Daftarkan profil pelanggan baru.
     *
     * @param  array{
     *     tenant_id: int,
     *     name: string,
     *     customer_code?: string|null,
     *     phone?: string|null,
     *     email?: string|null,
     *     customer_type?: string,
     *     customer_group_id?: int|null,
     *     company_name?: string|null,
     *     tax_id?: string|null,
     *     default_store_id?: int|null,
     *     is_loyalty_eligible?: bool,
     *     date_of_birth?: string|null,
     * } $data
     *
     * @throws ValidationException Jika nomor telepon duplikat untuk pelanggan aktif di tenant yang sama.
     */
    public function register(array $data): Customer
    {
        $tenantId = (int) $data['tenant_id'];
        $rawPhone = $data['phone'] ?? null;
        $normalizedPhone = $this->normalizePhoneNumber($rawPhone);

        // Validasi keunikan nomor telepon untuk pelanggan aktif di tingkat tenant
        if ($normalizedPhone !== null) {
            $duplicateExists = Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('phone', $normalizedPhone)
                ->whereNull('deleted_at')
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'phone' => "Nomor telepon {$normalizedPhone} sudah terdaftar untuk pelanggan aktif pada tenant ini.",
                ]);
            }
        }

        $customerCode = ! empty($data['customer_code'])
            ? (string) $data['customer_code']
            : $this->generateCustomerCode($tenantId);

        try {
            return DB::transaction(function () use ($tenantId, $data, $normalizedPhone, $customerCode) {
                /** @var Customer $customer */
                $customer = Customer::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'customer_code' => $customerCode,
                    'name' => $data['name'],
                    'phone' => $normalizedPhone,
                    'email' => $data['email'] ?? null,
                    'customer_type' => $data['customer_type'] ?? 'INDIVIDUAL',
                    'customer_group_id' => $data['customer_group_id'] ?? null,
                    'company_name' => $data['company_name'] ?? null,
                    'tax_id' => $data['tax_id'] ?? null,
                    'default_store_id' => $data['default_store_id'] ?? null,
                    'is_active' => true,
                    'is_loyalty_eligible' => $data['is_loyalty_eligible'] ?? true,
                    'date_of_birth' => $data['date_of_birth'] ?? null,
                    'join_date' => Carbon::today(),
                ]);

                // Inisialisasi akun loyalitas jika eligible
                if ($customer->is_loyalty_eligible) {
                    CustomerLoyaltyAccount::withoutGlobalScopes()->firstOrCreate(
                        [
                            'tenant_id' => $tenantId,
                            'customer_id' => $customer->id,
                        ],
                        [
                            'current_points' => 0,
                            'lifetime_points_earned' => 0,
                        ]
                    );
                }

                return $customer;
            });
        } catch (QueryException $e) {
            // Tangkap benturan unique index database (customers_tenant_id_phone_active_unique)
            if (str_contains($e->getMessage(), 'phone_active_unique') || str_contains($e->getMessage(), 'Duplicate entry')) {
                throw ValidationException::withMessages([
                    'phone' => "Nomor telepon {$normalizedPhone} sudah terdaftar untuk pelanggan aktif pada tenant ini (Database Constraint).",
                ]);
            }

            throw $e;
        }
    }

    /**
     * Normalisasi nomor telepon standar internasional E.164 (+62...).
     */
    public function normalizePhoneNumber(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        // Hapus seluruh karakter selain angka dan tanda '+'
        $cleaned = preg_replace('/[^\d+]/', '', trim($phone)) ?? '';

        if ($cleaned === '') {
            return null;
        }

        // Jika diawali 0, ganti dengan +62 (Indonesia)
        if (str_starts_with($cleaned, '0')) {
            return '+62' . substr($cleaned, 1);
        }

        // Jika diawali 62 tanpa '+', tambahkan '+'
        if (str_starts_with($cleaned, '62')) {
            return '+' . $cleaned;
        }

        // Jika diawali angka 8 (misal 812xxxx), tambahkan +62
        if (str_starts_with($cleaned, '8')) {
            return '+62' . $cleaned;
        }

        // Jika sudah diawali '+', kembalikan langsung
        if (str_starts_with($cleaned, '+')) {
            return $cleaned;
        }

        return '+' . $cleaned;
    }

    /**
     * Dapatkan atau buat profil pelanggan bawaan walk-in anonim.
     */
    public function getOrCreateWalkInCustomer(int $tenantId, ?int $storeId = null): Customer
    {
        /** @var Customer $walkIn */
        $walkIn = Customer::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'customer_code' => 'WALK-IN',
            ],
            [
                'name' => 'Pelanggan Walk-In (Umum)',
                'phone' => null,
                'customer_type' => 'INDIVIDUAL',
                'is_active' => true,
                'is_loyalty_eligible' => false,
                'default_store_id' => $storeId,
            ]
        );

        return $walkIn;
    }

    /**
     * Generate customer code unik di tingkat tenant.
     */
    private function generateCustomerCode(int $tenantId): string
    {
        $timestamp = (string) time();
        $random = str_pad((string) mt_rand(100, 999), 3, '0', STR_PAD_LEFT);

        return "CUST-{$tenantId}-" . substr($timestamp, -5) . $random;
    }
}
