<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Core\Support\EmailNormalizer;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\CustomerLoyaltyAccount;
use App\Models\CustomerMergeLog;
use App\Models\DiscountRedemption;
use App\Models\LoyaltyLedger;
use App\Models\Order;
use App\Models\ParkedCart;
use App\Models\User;
use App\Modules\Purchasing\Services\DocumentSequenceService;
use App\Modules\Settings\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function __construct(
        protected DocumentSequenceService $sequenceService,
        protected AuditTrailService $auditTrailService,
        protected TenantContext $tenantContext
    ) {}

    public function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $cleaned = preg_replace('/[^\d+]/', '', trim($phone));
        if ($cleaned === '' || $cleaned === null) {
            return null;
        }

        // Standardize local Indonesian format (08xxx -> +628xxx)
        if (str_starts_with($cleaned, '08')) {
            $cleaned = '+62'.substr($cleaned, 1);
        }

        return $cleaned;
    }

    public function normalizeEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        $normalized = EmailNormalizer::normalize($email);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Membuat data induk pelanggan baru (B2C / B2B).
     *
     * @param  array<string, mixed>  $data
     */
    public function createCustomer(array $data, ?User $actor = null): Customer
    {
        $tenantId = (int) ($actor && $actor->tenant_id ? $actor->tenant_id : $this->tenantContext->requireTenantId());

        return DB::transaction(function () use ($data, $actor, $tenantId) {
            $code = ! empty($data['customer_code'])
                ? trim((string) $data['customer_code'])
                : $this->sequenceService->nextNumber($tenantId, 'CUST');

            $existing = Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_code', $code)
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages([
                    'customer_code' => ["Kode pelanggan '{$code}' sudah digunakan pada tenant ini."],
                ]);
            }

            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                throw ValidationException::withMessages([
                    'name' => ['Nama pelanggan wajib diisi.'],
                ]);
            }

            $phone = $this->normalizePhone($data['phone'] ?? null);
            if ($phone !== null) {
                $phoneExists = Customer::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->where('phone', $phone)
                    ->exists();

                if ($phoneExists) {
                    throw ValidationException::withMessages([
                        'phone' => ["Nomor telepon '{$phone}' sudah digunakan oleh pelanggan aktif lain."],
                    ]);
                }
            }

            $email = $this->normalizeEmail($data['email'] ?? null);
            if ($email !== null) {
                $emailExists = Customer::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->where('email', $email)
                    ->exists();

                if ($emailExists) {
                    throw ValidationException::withMessages([
                        'email' => ["Alamat email '{$email}' sudah digunakan oleh pelanggan aktif lain."],
                    ]);
                }
            }

            $type = strtoupper((string) ($data['customer_type'] ?? 'INDIVIDUAL'));
            if (! in_array($type, ['INDIVIDUAL', 'COMPANY'], true)) {
                $type = 'INDIVIDUAL';
            }

            /** @var Customer $customer */
            $customer = Customer::create([
                'tenant_id' => $tenantId,
                'customer_code' => $code,
                'name' => $name,
                'customer_type' => $type,
                'customer_group_id' => ! empty($data['customer_group_id']) ? (int) $data['customer_group_id'] : null,
                'company_name' => $type === 'COMPANY' && ! empty($data['company_name']) ? trim((string) $data['company_name']) : null,
                'tax_id' => ! empty($data['tax_id']) ? trim((string) $data['tax_id']) : null,
                'business_registration_no' => ! empty($data['business_registration_no']) ? trim((string) $data['business_registration_no']) : null,
                'phone' => $phone,
                'email' => $email,
                'date_of_birth' => ! empty($data['date_of_birth']) ? $data['date_of_birth'] : null,
                'join_date' => ! empty($data['join_date']) ? $data['join_date'] : now()->toDateString(),
                'default_store_id' => ! empty($data['default_store_id']) ? (int) $data['default_store_id'] : null,
                'sales_rep_user_id' => ! empty($data['sales_rep_user_id']) ? (int) $data['sales_rep_user_id'] : null,
                'price_list_id' => ! empty($data['price_list_id']) ? (int) $data['price_list_id'] : null,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
                'is_loyalty_eligible' => isset($data['is_loyalty_eligible']) ? (bool) $data['is_loyalty_eligible'] : true,
            ]);

            // Inisialisasi CustomerLoyaltyAccount jika pelanggan eligible
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

            // Tambahkan alamat awal jika dilampirkan
            if (! empty($data['addresses']) && is_array($data['addresses'])) {
                foreach ($data['addresses'] as $addr) {
                    $this->addAddress($customer, $addr);
                }
            }

            // Tambahkan kontak awal jika dilampirkan (B2B)
            if (! empty($data['contacts']) && is_array($data['contacts'])) {
                foreach ($data['contacts'] as $contact) {
                    $this->addContact($customer, $contact);
                }
            }

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'customer.create',
                userId: $actor?->id,
                storeId: $customer->default_store_id,
                subjectType: Customer::class,
                subjectId: $customer->id,
                description: "Membuat pelanggan baru: {$customer->name} ({$customer->customer_code})",
                newValues: $customer->toArray()
            );

            return $customer;
        });
    }

    /**
     * Memperbarui profil pelanggan.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCustomer(Customer $customer, array $data, ?User $actor = null): Customer
    {
        $tenantId = (int) $customer->tenant_id;

        return DB::transaction(function () use ($customer, $data, $actor, $tenantId) {
            $oldValues = $customer->only([
                'name',
                'customer_type',
                'customer_group_id',
                'company_name',
                'tax_id',
                'business_registration_no',
                'phone',
                'email',
                'default_store_id',
                'sales_rep_user_id',
                'price_list_id',
                'is_active',
                'is_loyalty_eligible',
            ]);

            if (isset($data['phone'])) {
                $phone = $this->normalizePhone($data['phone']);
                if ($phone !== null && $phone !== $customer->phone) {
                    $phoneExists = Customer::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->whereNull('deleted_at')
                        ->where('phone', $phone)
                        ->where('id', '!=', $customer->id)
                        ->exists();

                    if ($phoneExists) {
                        throw ValidationException::withMessages([
                            'phone' => ["Nomor telepon '{$phone}' sudah digunakan oleh pelanggan aktif lain."],
                        ]);
                    }
                }
                $customer->phone = $phone;
            }

            if (isset($data['email'])) {
                $email = $this->normalizeEmail($data['email']);
                if ($email !== null && $email !== $customer->email) {
                    $emailExists = Customer::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->whereNull('deleted_at')
                        ->where('email', $email)
                        ->where('id', '!=', $customer->id)
                        ->exists();

                    if ($emailExists) {
                        throw ValidationException::withMessages([
                            'email' => ["Alamat email '{$email}' sudah digunakan oleh pelanggan aktif lain."],
                        ]);
                    }
                }
                $customer->email = $email;
            }

            if (isset($data['name'])) {
                $name = trim((string) $data['name']);
                if ($name === '') {
                    throw ValidationException::withMessages([
                        'name' => ['Nama pelanggan tidak boleh kosong.'],
                    ]);
                }
                $customer->name = $name;
            }

            if (isset($data['customer_type'])) {
                $type = strtoupper((string) $data['customer_type']);
                if (in_array($type, ['INDIVIDUAL', 'COMPANY'], true)) {
                    $customer->customer_type = $type;
                }
            }

            if (array_key_exists('customer_group_id', $data)) {
                $customer->customer_group_id = ! empty($data['customer_group_id']) ? (int) $data['customer_group_id'] : null;
            }

            if (array_key_exists('company_name', $data)) {
                $customer->company_name = ! empty($data['company_name']) ? trim((string) $data['company_name']) : null;
            }

            if (array_key_exists('tax_id', $data)) {
                $customer->tax_id = ! empty($data['tax_id']) ? trim((string) $data['tax_id']) : null;
            }

            if (array_key_exists('business_registration_no', $data)) {
                $customer->business_registration_no = ! empty($data['business_registration_no']) ? trim((string) $data['business_registration_no']) : null;
            }

            if (array_key_exists('default_store_id', $data)) {
                $customer->default_store_id = ! empty($data['default_store_id']) ? (int) $data['default_store_id'] : null;
            }

            if (array_key_exists('sales_rep_user_id', $data)) {
                $customer->sales_rep_user_id = ! empty($data['sales_rep_user_id']) ? (int) $data['sales_rep_user_id'] : null;
            }

            if (array_key_exists('price_list_id', $data)) {
                $customer->price_list_id = ! empty($data['price_list_id']) ? (int) $data['price_list_id'] : null;
            }

            if (isset($data['is_active'])) {
                $customer->is_active = (bool) $data['is_active'];
            }

            if (isset($data['is_loyalty_eligible'])) {
                $customer->is_loyalty_eligible = (bool) $data['is_loyalty_eligible'];
            }

            if (isset($data['date_of_birth'])) {
                $customer->date_of_birth = $data['date_of_birth'];
            }

            $customer->save();

            $newValues = $customer->only(array_keys($oldValues));

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'customer.update',
                userId: $actor?->id,
                storeId: $customer->default_store_id,
                subjectType: Customer::class,
                subjectId: $customer->id,
                description: "Memperbarui data pelanggan: {$customer->name} ({$customer->customer_code})",
                oldValues: $oldValues,
                newValues: $newValues
            );

            return $customer;
        });
    }

    /**
     * Menghapus (soft delete) pelanggan.
     */
    public function deleteCustomer(Customer $customer, ?User $actor = null, string $reason = ''): bool
    {
        $tenantId = (int) $customer->tenant_id;

        return DB::transaction(function () use ($customer, $actor, $tenantId, $reason) {
            $customer->is_active = false;
            $customer->save();

            $deleted = (bool) $customer->delete();

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'customer.delete',
                userId: $actor?->id,
                storeId: $customer->default_store_id,
                subjectType: Customer::class,
                subjectId: $customer->id,
                description: "Menghapus pelanggan: {$customer->name} ({$customer->customer_code}). Alasan: {$reason}",
                oldValues: ['is_active' => true],
                newValues: ['is_active' => false, 'deleted_at' => now()]
            );

            return $deleted;
        });
    }

    /**
     * Menggabungkan dua pelanggan duplikat secara atomik dengan row locking kanonis (ORDER BY id ASC).
     */
    public function mergeCustomers(Customer $source, Customer $target, User $actor, string $reason): CustomerMergeLog
    {
        if ((int) $source->tenant_id !== (int) $target->tenant_id) {
            throw new DomainException('Tidak dapat menggabungkan pelanggan dari tenant yang berbeda.');
        }

        if ($source->id === $target->id) {
            throw new DomainException('Pelanggan sumber dan target tidak boleh sama.');
        }

        $tenantId = (int) $source->tenant_id;

        return DB::transaction(function () use ($source, $target, $actor, $tenantId, $reason) {
            // 1. Akuisisi row lock deterministik pada kedua record (ORDER BY id ASC)
            $customerIds = [$source->id, $target->id];
            sort($customerIds);

            $lockedCustomers = Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $customerIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Customer $lockedSource */
            $lockedSource = $lockedCustomers->get($source->id);
            /** @var Customer $lockedTarget */
            $lockedTarget = $lockedCustomers->get($target->id);

            if (! $lockedSource || ! $lockedTarget) {
                throw new DomainException('Salah satu atau kedua pelanggan tidak ditemukan untuk di-lock.');
            }

            if ($lockedSource->merged_into_customer_id !== null) {
                throw new DomainException("Pelanggan sumber '{$lockedSource->customer_code}' sudah pernah digabungkan sebelumnya.");
            }

            if ($lockedTarget->merged_into_customer_id !== null) {
                throw new DomainException("Pelanggan target '{$lockedTarget->customer_code}' sudah tidak aktif karena telah digabungkan ke akun lain.");
            }

            $reassigned = [];

            // 2. Reassign Orders
            $ordersReassigned = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $lockedSource->id)
                ->update(['customer_id' => $lockedTarget->id]);
            $reassigned['orders'] = $ordersReassigned;

            // 3. Reassign Addresses (tandai non-default agar tidak konflik dengan target)
            $addressesReassigned = CustomerAddress::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $lockedSource->id)
                ->update([
                    'customer_id' => $lockedTarget->id,
                    'is_default_billing' => false,
                    'is_default_shipping' => false,
                ]);
            $reassigned['customer_addresses'] = $addressesReassigned;

            // 4. Reassign Contacts (tandai non-primary)
            $contactsReassigned = CustomerContact::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $lockedSource->id)
                ->update([
                    'customer_id' => $lockedTarget->id,
                    'is_primary' => false,
                ]);
            $reassigned['customer_contacts'] = $contactsReassigned;

            // 5. Reassign Discount Redemptions
            if (DB::getSchemaBuilder()->hasTable('discount_redemptions')) {
                $discountsReassigned = DiscountRedemption::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $lockedSource->id)
                    ->update(['customer_id' => $lockedTarget->id]);
                $reassigned['discount_redemptions'] = $discountsReassigned;
            }

            // 6. Reassign Parked Carts
            if (DB::getSchemaBuilder()->hasTable('parked_carts')) {
                $parkedCartsReassigned = ParkedCart::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $lockedSource->id)
                    ->update(['customer_id' => $lockedTarget->id]);
                $reassigned['parked_carts'] = $parkedCartsReassigned;
            }

            // 7. Reassign Loyalty Ledgers
            if (DB::getSchemaBuilder()->hasTable('loyalty_ledger')) {
                $loyaltyReassigned = LoyaltyLedger::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $lockedSource->id)
                    ->update(['customer_id' => $lockedTarget->id]);
                $reassigned['loyalty_ledger'] = $loyaltyReassigned;
            }

            // 8. Tandai Source Customer sebagai merged dan non-aktifkan
            $lockedSource->merged_into_customer_id = $lockedTarget->id;
            $lockedSource->is_active = false;
            $lockedSource->save();
            $lockedSource->delete(); // Soft delete

            // 9. Simpan jejak merge ke customer_merge_logs
            $mergeLog = CustomerMergeLog::create([
                'tenant_id' => $tenantId,
                'source_customer_id' => $lockedSource->id,
                'target_customer_id' => $lockedTarget->id,
                'merged_by_user_id' => $actor->id,
                'reason' => $reason,
                'reassigned_records_json' => $reassigned,
            ]);

            // 10. Audit Log
            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'customer.merge',
                userId: $actor->id,
                storeId: $lockedTarget->default_store_id,
                subjectType: Customer::class,
                subjectId: $lockedTarget->id,
                description: "Menggabungkan pelanggan {$lockedSource->customer_code} ke {$lockedTarget->customer_code}. Alasan: {$reason}",
                metadata: [
                    'source_customer_id' => $lockedSource->id,
                    'target_customer_id' => $lockedTarget->id,
                    'reassigned_counts' => $reassigned,
                ]
            );

            return $mergeLog;
        });
    }

    /**
     * Tambah alamat pelanggan.
     */
    public function addAddress(Customer $customer, array $data): CustomerAddress
    {
        $tenantId = (int) $customer->tenant_id;

        return DB::transaction(function () use ($customer, $data, $tenantId) {
            $isDefaultBilling = ! empty($data['is_default_billing']);
            $isDefaultShipping = ! empty($data['is_default_shipping']);

            if ($isDefaultBilling) {
                CustomerAddress::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $customer->id)
                    ->update(['is_default_billing' => false]);
            }

            if ($isDefaultShipping) {
                CustomerAddress::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $customer->id)
                    ->update(['is_default_shipping' => false]);
            }

            return CustomerAddress::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'address_type' => $data['address_type'] ?? 'SHIPPING',
                'label' => $data['label'] ?? null,
                'recipient_name' => $data['recipient_name'] ?? $customer->name,
                'phone' => $this->normalizePhone($data['phone'] ?? $customer->phone),
                'address_line1' => $data['address_line1'],
                'address_line2' => $data['address_line2'] ?? null,
                'city' => $data['city'] ?? null,
                'province' => $data['province'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'country_code' => $data['country_code'] ?? 'ID',
                'is_default_billing' => $isDefaultBilling,
                'is_default_shipping' => $isDefaultShipping,
            ]);
        });
    }

    /**
     * Perbarui alamat pelanggan.
     */
    public function updateAddress(CustomerAddress $address, array $data): CustomerAddress
    {
        $tenantId = (int) $address->tenant_id;

        return DB::transaction(function () use ($address, $data, $tenantId) {
            if (! empty($data['is_default_billing'])) {
                CustomerAddress::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $address->customer_id)
                    ->where('id', '!=', $address->id)
                    ->update(['is_default_billing' => false]);
                $address->is_default_billing = true;
            } elseif (array_key_exists('is_default_billing', $data)) {
                $address->is_default_billing = (bool) $data['is_default_billing'];
            }

            if (! empty($data['is_default_shipping'])) {
                CustomerAddress::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $address->customer_id)
                    ->where('id', '!=', $address->id)
                    ->update(['is_default_shipping' => false]);
                $address->is_default_shipping = true;
            } elseif (array_key_exists('is_default_shipping', $data)) {
                $address->is_default_shipping = (bool) $data['is_default_shipping'];
            }

            if (isset($data['address_type'])) {
                $address->address_type = $data['address_type'];
            }
            if (array_key_exists('label', $data)) {
                $address->label = $data['label'];
            }
            if (isset($data['recipient_name'])) {
                $address->recipient_name = $data['recipient_name'];
            }
            if (array_key_exists('phone', $data)) {
                $address->phone = $this->normalizePhone($data['phone']);
            }
            if (isset($data['address_line1'])) {
                $address->address_line1 = $data['address_line1'];
            }
            if (array_key_exists('address_line2', $data)) {
                $address->address_line2 = $data['address_line2'];
            }
            if (array_key_exists('city', $data)) {
                $address->city = $data['city'];
            }
            if (array_key_exists('province', $data)) {
                $address->province = $data['province'];
            }
            if (array_key_exists('postal_code', $data)) {
                $address->postal_code = $data['postal_code'];
            }
            if (array_key_exists('country_code', $data)) {
                $address->country_code = $data['country_code'];
            }

            $address->save();

            return $address;
        });
    }

    /**
     * Hapus alamat pelanggan.
     */
    public function deleteAddress(CustomerAddress $address): bool
    {
        return (bool) $address->delete();
    }

    /**
     * Tambah kontak narahubung pelanggan (B2B).
     */
    public function addContact(Customer $customer, array $data): CustomerContact
    {
        $tenantId = (int) $customer->tenant_id;

        return DB::transaction(function () use ($customer, $data, $tenantId) {
            $isPrimary = ! empty($data['is_primary']);

            if ($isPrimary) {
                CustomerContact::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $customer->id)
                    ->update(['is_primary' => false]);
            }

            return CustomerContact::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'name' => trim((string) $data['name']),
                'role_title' => ! empty($data['role_title']) ? trim((string) $data['role_title']) : null,
                'email' => $this->normalizeEmail($data['email'] ?? null),
                'phone' => $this->normalizePhone($data['phone'] ?? null),
                'is_primary' => $isPrimary,
            ]);
        });
    }

    /**
     * Perbarui kontak narahubung.
     */
    public function updateContact(CustomerContact $contact, array $data): CustomerContact
    {
        $tenantId = (int) $contact->tenant_id;

        return DB::transaction(function () use ($contact, $data, $tenantId) {
            if (! empty($data['is_primary'])) {
                CustomerContact::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $contact->customer_id)
                    ->where('id', '!=', $contact->id)
                    ->update(['is_primary' => false]);
                $contact->is_primary = true;
            } elseif (array_key_exists('is_primary', $data)) {
                $contact->is_primary = (bool) $data['is_primary'];
            }

            if (isset($data['name'])) {
                $contact->name = trim((string) $data['name']);
            }
            if (array_key_exists('role_title', $data)) {
                $contact->role_title = ! empty($data['role_title']) ? trim((string) $data['role_title']) : null;
            }
            if (array_key_exists('email', $data)) {
                $contact->email = $this->normalizeEmail($data['email']);
            }
            if (array_key_exists('phone', $data)) {
                $contact->phone = $this->normalizePhone($data['phone']);
            }

            $contact->save();

            return $contact;
        });
    }

    /**
     * Hapus kontak narahubung.
     */
    public function deleteContact(CustomerContact $contact): bool
    {
        return (bool) $contact->delete();
    }
}
