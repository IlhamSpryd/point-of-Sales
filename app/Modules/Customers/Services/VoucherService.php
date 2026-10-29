<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Models\Customer;
use App\Models\User;
use App\Modules\Customers\Enums\VoucherDiscountTypeEnum;
use App\Modules\Customers\Enums\VoucherStatusEnum;
use App\Modules\Customers\Models\Voucher;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoucherService
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Buat kode voucher baru.
     *
     * @param  array<string, mixed>  $data
     */
    public function createVoucher(array $data, User $actor): Voucher
    {
        $tenantId = (int) $this->tenantContext->requireTenantId();

        $code = strtoupper(trim((string) ($data['code'] ?? $data['voucher_code'] ?? '')));
        if (empty($code)) {
            throw ValidationException::withMessages(['code' => ['Kode voucher wajib diisi.']]);
        }

        $existing = Voucher::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages(['code' => ['Kode voucher sudah terdaftar pada tenant ini.']]);
        }

        $discountType = $data['discount_type'] instanceof VoucherDiscountTypeEnum
            ? $data['discount_type']
            : VoucherDiscountTypeEnum::from((string) ($data['discount_type'] ?? 'PERCENTAGE'));

        $discountValue = bcadd((string) ($data['discount_value'] ?? '0'), '0', 2);
        if (bccomp($discountValue, '0.00', 2) <= 0) {
            throw ValidationException::withMessages(['discount_value' => ['Nilai diskon voucher harus lebih besar dari 0.']]);
        }

        if ($discountType === VoucherDiscountTypeEnum::PERCENTAGE && bccomp($discountValue, '100.00', 2) > 0) {
            throw ValidationException::withMessages(['discount_value' => ['Persentase diskon voucher tidak boleh melebihi 100%.']]);
        }

        return Voucher::create([
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => (string) ($data['name'] ?? $code),
            'discount_id' => ! empty($data['discount_id']) ? (int) $data['discount_id'] : null,
            'customer_id' => ! empty($data['customer_id']) ? (int) $data['customer_id'] : null,
            'customer_group_id' => ! empty($data['customer_group_id']) ? (int) $data['customer_group_id'] : null,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'max_discount_amount' => ! empty($data['max_discount_amount']) ? bcadd((string) $data['max_discount_amount'], '0', 2) : null,
            'min_order_amount' => bcadd((string) ($data['min_order_amount'] ?? '0'), '0', 2),
            'max_uses' => (int) ($data['max_uses'] ?? $data['max_uses_global'] ?? 1),
            'max_uses_per_customer' => (int) ($data['max_uses_per_customer'] ?? 1),
            'used_count' => 0,
            'valid_from' => ! empty($data['valid_from']) ? Carbon::parse($data['valid_from']) : Carbon::now(),
            'valid_until' => ! empty($data['valid_until']) ? Carbon::parse($data['valid_until']) : null,
            'status' => VoucherStatusEnum::ACTIVE,
        ]);
    }

    /**
     * Validasi kelayakan voucher terhadap keranjang / transaksi belanja pelanggan.
     *
     * @return array{is_valid: bool, discount_amount: string, voucher: Voucher, reason?: string}
     */
    public function validateVoucher(string $code, ?Customer $customer, string $orderAmount): array
    {
        $tenantId = (int) $this->tenantContext->requireTenantId();
        $code = strtoupper(trim($code));

        /** @var Voucher|null $voucher */
        $voucher = Voucher::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();

        if (! $voucher) {
            throw new DomainException("Voucher dengan kode '{$code}' tidak ditemukan.");
        }

        if (! $voucher->isValidNow()) {
            throw new DomainException("Voucher '{$code}' sudah tidak aktif, kedaluwarsa, atau batas pemakaian telah tercapai.");
        }

        // Cek syarat minimum transaksi
        if (bccomp($orderAmount, (string) $voucher->min_order_amount, 2) < 0) {
            $formattedMin = number_format((float) $voucher->min_order_amount, 0, ',', '.');
            throw new DomainException("Total transaksi belanja belum memenuhi syarat minimum voucher (Rp {$formattedMin}).");
        }

        // Cek eksklusivitas pelanggan
        if ($voucher->customer_id !== null) {
            if (! $customer || (int) $customer->id !== (int) $voucher->customer_id) {
                throw new DomainException('Voucher ini eksklusif dan tidak berlaku untuk akun pelanggan ini.');
            }
        }

        // Cek grup pelanggan
        if ($voucher->customer_group_id !== null) {
            if (! $customer || (int) $customer->customer_group_id !== (int) $voucher->customer_group_id) {
                throw new DomainException('Voucher ini hanya berlaku untuk grup pelanggan tertentu.');
            }
        }

        // Hitung diskon yang diperoleh
        $discountAmount = '0.00';
        if ($voucher->discount_type === VoucherDiscountTypeEnum::PERCENTAGE) {
            $rawDiscount = bcdiv(bcmul($orderAmount, (string) $voucher->discount_value, 4), '100', 2);
            if ($voucher->max_discount_amount && bccomp($rawDiscount, (string) $voucher->max_discount_amount, 2) > 0) {
                $discountAmount = (string) $voucher->max_discount_amount;
            } else {
                $discountAmount = $rawDiscount;
            }
        } else {
            // FIXED_AMOUNT
            $discountAmount = bccomp((string) $voucher->discount_value, $orderAmount, 2) > 0
                ? $orderAmount
                : (string) $voucher->discount_value;
        }

        return [
            'is_valid' => true,
            'discount_amount' => $discountAmount,
            'voucher' => $voucher,
        ];
    }

    /**
     * Gunakan voucher pada transaksi penjualan dengan row locking A0/A8.
     *
     * @return array{voucher_id: int, code: string, discount_amount: string, used_count: int}
     */
    public function redeemVoucher(
        string $code,
        ?Customer $customer,
        string $orderAmount,
        ?int $orderId = null,
        ?User $actor = null
    ): array {
        $tenantId = (int) $this->tenantContext->requireTenantId();
        $code = strtoupper(trim($code));

        return DB::transaction(function () use ($tenantId, $code, $customer, $orderAmount) {
            /** @var Voucher $lockedVoucher */
            $lockedVoucher = Voucher::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('code', $code)
                ->lockForUpdate()
                ->firstOrFail();

            $validation = $this->validateVoucher($code, $customer, $orderAmount);
            $discountAmount = $validation['discount_amount'];

            $lockedVoucher->used_count += 1;
            if ($lockedVoucher->max_uses > 0 && $lockedVoucher->used_count >= $lockedVoucher->max_uses) {
                $lockedVoucher->status = VoucherStatusEnum::EXPIRED;
            }
            $lockedVoucher->save();

            return [
                'voucher_id' => (int) $lockedVoucher->id,
                'code' => $lockedVoucher->code,
                'discount_amount' => $discountAmount,
                'used_count' => (int) $lockedVoucher->used_count,
            ];
        });
    }
}
