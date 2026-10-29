<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Sales;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Enums\CreditStatusEnum;
use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Customers\Services\CustomerCreditService;
use App\Modules\Sales\Enums\ArLedgerEntryTypeEnum;
use App\Modules\Sales\Enums\CustomerInvoiceStatusEnum;
use App\Modules\Sales\Enums\SalesOrderInvoicingStatusEnum;
use App\Modules\Sales\Models\AccountingReceivableOutbox;
use App\Modules\Sales\Models\ArLedgerEntry;
use App\Modules\Sales\Models\CustomerInvoice;
use App\Modules\Sales\Services\AccountsReceivableService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountsReceivableServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AccountsReceivableService $arService;

    protected SalesOrderService $salesOrderService;

    protected CustomerCreditService $creditService;

    protected Tenant $tenant;

    protected Store $store;

    protected User $owner;

    protected Customer $customer;

    protected Product $product;

    protected function afterSubclassSetUp(): void
    {
        $this->arService = app(AccountsReceivableService::class);
        $this->salesOrderService = app(SalesOrderService::class);
        $this->creditService = app(CustomerCreditService::class);

        $context = app(TenantContext::class);

        $this->tenant = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'PT Distribusi Niaga Sejahtera',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'name' => 'Gudang Utama Jakarta',
        ]);
        $context->setStoreId($this->store->id);

        $ownerRole = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenant->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $this->owner = User::create([
            'name' => 'Direktur Utama',
            'email' => 'direktur@distribusiniaga.test',
            'password' => bcrypt('password'),
            'role_id' => $ownerRole->id,
            'is_active' => true,
        ]);
        $this->owner->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->customer = Customer::create([
            'customer_code' => 'CUST-B2B-101',
            'name' => 'PT Jaya Abadi Sentosa',
            'customer_type' => 'COMPANY',
            'company_name' => 'PT Jaya Abadi Sentosa',
            'tax_id' => '01.999.888.7-654.000',
            'phone' => '+6281122334455',
            'email' => 'finance@jayaabadi.test',
            'is_active' => true,
        ]);

        // Beri limit kredit 50.000.000, termin 30 hari
        CustomerCreditAccount::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'credit_limit' => '50000000.00',
            'payment_terms_days' => 30,
            'grace_period_days' => 7,
            'credit_status' => CreditStatusEnum::ACTIVE,
            'reserved_exposure' => '0.00',
            'invoiced_exposure' => '0.00',
            'unapplied_credit_balance' => '0.00',
        ]);

        $category = Category::create([
            'category_name' => 'Bahan Baku Makanan',
            'category_code' => 'RAW-FOOD',
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Tepung Terigu Premium 25kg',
            'product_code' => 'FLOUR-PREM-25',
            'product_price' => 250000.00,
            'is_active' => true,
            'status' => 'active',
        ]);
    }

    public function test_create_and_post_invoice_from_sales_order_transfers_exposure_without_double_counting(): void
    {
        $this->actingAs($this->owner);

        // 1. Buat dan Konfirmasi Sales Order (Rp 10.000.000)
        // 40 sak x 250.000 = 10.000.000
        $so = $this->salesOrderService->createOrder([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'payment_terms_days' => 30,
            'customer_po_number' => 'PO-JAS-2027-01',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '40.0000', 'unit_price' => '250000.00'],
            ],
        ], $this->owner);

        $this->salesOrderService->confirmOrder($so, $this->owner);

        $account = CustomerCreditAccount::where('customer_id', $this->customer->id)->first();
        // Eksposur awal ter-reservasi di SO
        $this->assertSame('10000000.00', (string) $account->reserved_exposure);
        $this->assertSame('0.00', (string) $account->invoiced_exposure);
        $this->assertSame('10000000.00', $account->getTotalExposure());
        $this->assertSame('40000000.00', $account->getAvailableCredit());

        // 2. Terbitkan Faktur (DRAFT) dari Sales Order
        $invoice = $this->arService->createInvoice($so, [
            'invoice_date' => Carbon::today()->toDateString(),
        ], $this->owner);

        $this->assertSame(CustomerInvoiceStatusEnum::DRAFT, $invoice->status);
        $this->assertSame('10000000.00', (string) $invoice->total_amount);
        $this->assertSame('10000000.00', (string) $invoice->balance_due);
        $this->assertCount(1, $invoice->lines);

        // Saat masih DRAFT, eksposur belum berpindah
        $account->refresh();
        $this->assertSame('10000000.00', (string) $account->reserved_exposure);
        $this->assertSame('0.00', (string) $account->invoiced_exposure);

        // 3. Posting Faktur (POSTED) -> Eksposur dipindahkan atomik
        $postedInvoice = $this->arService->postInvoice($invoice, $this->owner);
        $this->assertSame(CustomerInvoiceStatusEnum::POSTED, $postedInvoice->status);
        $this->assertNotNull($postedInvoice->posted_at);
        $this->assertSame($this->owner->id, $postedInvoice->posted_by_user_id);

        $account->refresh();
        // Reserved eksposur berkurang dari 10jt ke 0
        $this->assertSame('0.00', (string) $account->reserved_exposure);
        // Invoiced eksposur bertambah menjadi 10jt
        $this->assertSame('10000000.00', (string) $account->invoiced_exposure);
        // TOTAL EKSPOSUR TETAP 10.000.000 (BEBAS HITUNG GANDA!)
        $this->assertSame('10000000.00', $account->getTotalExposure());
        $this->assertSame('40000000.00', $account->getAvailableCredit());

        // Status Sales Order terupdate
        $so->refresh();
        $this->assertSame(SalesOrderInvoicingStatusEnum::FULLY_INVOICED, $so->invoicing_status);

        // 4. Verifikasi ArLedgerEntry (Subledger Append-Only)
        $ledgerEntry = ArLedgerEntry::where('tenant_id', $this->tenant->id)
            ->where('customer_invoice_id', $postedInvoice->id)
            ->first();
        $this->assertNotNull($ledgerEntry);
        $this->assertSame(ArLedgerEntryTypeEnum::INVOICE_DEBIT, $ledgerEntry->entry_type);
        $this->assertSame('10000000.00', (string) $ledgerEntry->debit_amount);
        $this->assertSame('0.00', (string) $ledgerEntry->credit_amount);
        $this->assertSame('10000000.00', (string) $ledgerEntry->running_balance_snapshot);

        // 5. Verifikasi AccountingReceivableOutbox
        $outbox = AccountingReceivableOutbox::where('tenant_id', $this->tenant->id)
            ->where('source_document_id', $postedInvoice->id)
            ->first();
        $this->assertNotNull($outbox);
        $this->assertSame('INVOICE_POSTED', $outbox->event_type);
        $this->assertSame('pending', $outbox->status);
    }

    public function test_payment_allocation_supports_full_and_partial_payment_and_releases_invoiced_exposure(): void
    {
        $this->actingAs($this->owner);

        // Buat Invoice langsung (Direct Invoice) senilai Rp 8.000.000
        $invoice = $this->arService->createInvoice([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'payment_terms_days' => 14,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '32.0000', 'unit_price' => '250000.00'],
            ],
        ], ['post_immediately' => true], $this->owner);

        $account = CustomerCreditAccount::where('customer_id', $this->customer->id)->first();
        $this->assertSame('8000000.00', (string) $account->invoiced_exposure);

        // 1. Pembayaran Parsial: Bayar Rp 5.000.000
        $res1 = $this->arService->recordAndAllocatePayment(
            customerId: (int) $this->customer->id,
            paymentData: [
                'amount' => '5000000.00',
                'payment_reference' => 'TRF-BCA-001',
            ],
            invoiceAllocations: [
                ['invoice_id' => $invoice->id, 'amount' => '5000000.00'],
            ],
            actor: $this->owner
        );

        $this->assertSame('5000000.00', $res1['total_allocated']);
        $this->assertSame('0.00', $res1['unapplied_credit']);

        $invoice->refresh();
        $this->assertSame(CustomerInvoiceStatusEnum::PARTIALLY_PAID, $invoice->status);
        $this->assertSame('5000000.00', (string) $invoice->paid_amount);
        $this->assertSame('3000000.00', (string) $invoice->balance_due);

        $account->refresh();
        // Invoiced exposure turun: 8jt - 5jt = 3jt
        $this->assertSame('3000000.00', (string) $account->invoiced_exposure);
        $this->assertSame('47000000.00', $account->getAvailableCredit());

        // 2. Pelunasan Penuh: Bayar sisa Rp 3.000.000
        $res2 = $this->arService->recordAndAllocatePayment(
            customerId: (int) $this->customer->id,
            paymentData: [
                'amount' => '3000000.00',
                'payment_reference' => 'TRF-BCA-002',
            ],
            invoiceAllocations: [
                ['invoice_id' => $invoice->id, 'amount' => '3000000.00'],
            ],
            actor: $this->owner
        );

        $invoice->refresh();
        $this->assertSame(CustomerInvoiceStatusEnum::PAID, $invoice->status);
        $this->assertSame('8000000.00', (string) $invoice->paid_amount);
        $this->assertSame('0.00', (string) $invoice->balance_due);

        $account->refresh();
        $this->assertSame('0.00', (string) $account->invoiced_exposure);
        $this->assertSame('50000000.00', $account->getAvailableCredit());
    }

    public function test_payment_overpayment_is_credited_to_unapplied_credit_balance(): void
    {
        $this->actingAs($this->owner);

        // Faktur Rp 4.000.000
        $invoice = $this->arService->createInvoice([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '16.0000', 'unit_price' => '250000.00'],
            ],
        ], ['post_immediately' => true], $this->owner);

        // Pembayaran masuk Rp 5.000.000 (lebih bayar Rp 1.000.000)
        $res = $this->arService->recordAndAllocatePayment(
            customerId: (int) $this->customer->id,
            paymentData: [
                'amount' => '5000000.00',
                'payment_reference' => 'TRF-OVERPAY',
            ],
            invoiceAllocations: [
                ['invoice_id' => $invoice->id, 'amount' => '4000000.00'],
            ],
            actor: $this->owner
        );

        $this->assertSame('4000000.00', $res['total_allocated']);
        $this->assertSame('1000000.00', $res['unapplied_credit']);

        $invoice->refresh();
        $this->assertSame(CustomerInvoiceStatusEnum::PAID, $invoice->status);
        $this->assertSame('0.00', (string) $invoice->balance_due);

        $account = CustomerCreditAccount::where('customer_id', $this->customer->id)->first();
        $this->assertSame('0.00', (string) $account->invoiced_exposure);
        $this->assertSame('1000000.00', (string) $account->unapplied_credit_balance);
        // Available credit bertambah karena unapplied credit
        $this->assertSame('50000000.00', $account->getAvailableCredit());
    }

    public function test_reverse_payment_allocation_restores_invoice_balance_and_invoiced_exposure(): void
    {
        $this->actingAs($this->owner);

        $invoice = $this->arService->createInvoice([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '20.0000', 'unit_price' => '250000.00'],
            ],
        ], ['post_immediately' => true], $this->owner); // Total 5jt

        $res = $this->arService->recordAndAllocatePayment(
            customerId: (int) $this->customer->id,
            paymentData: ['amount' => '5000000.00', 'payment_reference' => 'CEK-TOLAK'],
            invoiceAllocations: [['invoice_id' => $invoice->id, 'amount' => '5000000.00']],
            actor: $this->owner
        );

        $allocation = $res['allocations'][0];
        $invoice->refresh();
        $this->assertSame(CustomerInvoiceStatusEnum::PAID, $invoice->status);

        // Eksekusi Reversal (Cek Bilyet Giro Ditolak Kliring)
        $reversed = $this->arService->reversePaymentAllocation($allocation, $this->owner, 'Cek ditolak oleh bank penerbit');
        $this->assertTrue($reversed->is_reversed);
        $this->assertNotNull($reversed->reversed_at);

        $invoice->refresh();
        $this->assertSame(CustomerInvoiceStatusEnum::POSTED, $invoice->status);
        $this->assertSame('0.00', (string) $invoice->paid_amount);
        $this->assertSame('5000000.00', (string) $invoice->balance_due);

        $account = CustomerCreditAccount::where('customer_id', $this->customer->id)->first();
        // Invoiced exposure kembali menjadi 5jt
        $this->assertSame('5000000.00', (string) $account->invoiced_exposure);
    }

    public function test_void_invoice_cancels_receivable_and_restores_sales_order_exposure(): void
    {
        $this->actingAs($this->owner);

        $so = $this->salesOrderService->createOrder([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '10.0000', 'unit_price' => '250000.00'],
            ],
        ], $this->owner);
        $this->salesOrderService->confirmOrder($so, $this->owner);

        $invoice = $this->arService->createInvoice($so, ['post_immediately' => true], $this->owner);
        $this->assertSame(CustomerInvoiceStatusEnum::POSTED, $invoice->status);

        $account = CustomerCreditAccount::where('customer_id', $this->customer->id)->first();
        $this->assertSame('2500000.00', (string) $account->invoiced_exposure);
        $this->assertSame('0.00', (string) $account->reserved_exposure);

        // Batalkan Faktur (VOID)
        $voided = $this->arService->voidInvoice($invoice, $this->owner, 'Salah memilih alamat cabang pengiriman');
        $this->assertSame(CustomerInvoiceStatusEnum::VOIDED, $voided->status);
        $this->assertSame('0.00', (string) $voided->balance_due);

        $account->refresh();
        // Invoiced exposure dibatalkan kembali ke 0
        $this->assertSame('0.00', (string) $account->invoiced_exposure);
        // Reserved exposure dikembalikan ke SO
        $this->assertSame('2500000.00', (string) $account->reserved_exposure);
    }

    public function test_ar_aging_and_customer_statement_reconciliation(): void
    {
        $this->actingAs($this->owner);

        // Buat 3 faktur:
        // Inv 1: Jatuh tempo 45 hari lalu (Aging 31-60) -> Rp 2.000.000
        $inv1 = CustomerInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'invoice_number' => 'INV-TEST-01',
            'customer_id' => $this->customer->id,
            'status' => CustomerInvoiceStatusEnum::POSTED,
            'invoice_date' => Carbon::today()->subDays(60)->toDateString(),
            'due_date' => Carbon::today()->subDays(45)->toDateString(),
            'total_amount' => '2000000.00',
            'balance_due' => '2000000.00',
        ]);
        ArLedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'customer_invoice_id' => $inv1->id,
            'entry_date' => Carbon::today()->subDays(60),
            'entry_type' => ArLedgerEntryTypeEnum::INVOICE_DEBIT,
            'reference_type' => CustomerInvoice::class,
            'reference_id' => $inv1->id,
            'reference_number' => $inv1->invoice_number,
            'debit_amount' => '2000000.00',
            'running_balance_snapshot' => '2000000.00',
        ]);

        // Inv 2: Jatuh tempo 10 hari lalu (Aging 1-30) -> Rp 3.000.000
        $inv2 = CustomerInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'invoice_number' => 'INV-TEST-02',
            'customer_id' => $this->customer->id,
            'status' => CustomerInvoiceStatusEnum::POSTED,
            'invoice_date' => Carbon::today()->subDays(20)->toDateString(),
            'due_date' => Carbon::today()->subDays(10)->toDateString(),
            'total_amount' => '3000000.00',
            'balance_due' => '3000000.00',
        ]);
        ArLedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'customer_invoice_id' => $inv2->id,
            'entry_date' => Carbon::today()->subDays(20),
            'entry_type' => ArLedgerEntryTypeEnum::INVOICE_DEBIT,
            'reference_type' => CustomerInvoice::class,
            'reference_id' => $inv2->id,
            'reference_number' => $inv2->invoice_number,
            'debit_amount' => '3000000.00',
            'running_balance_snapshot' => '5000000.00',
        ]);

        // Inv 3: Jatuh tempo 15 hari lagi (Current) -> Rp 5.000.000
        $inv3 = CustomerInvoice::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'invoice_number' => 'INV-TEST-03',
            'customer_id' => $this->customer->id,
            'status' => CustomerInvoiceStatusEnum::POSTED,
            'invoice_date' => Carbon::today()->toDateString(),
            'due_date' => Carbon::today()->addDays(15)->toDateString(),
            'total_amount' => '5000000.00',
            'balance_due' => '5000000.00',
        ]);
        ArLedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'customer_invoice_id' => $inv3->id,
            'entry_date' => Carbon::today(),
            'entry_type' => ArLedgerEntryTypeEnum::INVOICE_DEBIT,
            'reference_type' => CustomerInvoice::class,
            'reference_id' => $inv3->id,
            'reference_number' => $inv3->invoice_number,
            'debit_amount' => '5000000.00',
            'running_balance_snapshot' => '10000000.00',
        ]);

        // 1. Verifikasi Laporan Aging
        $aging = $this->arService->getAgingReport($this->tenant->id);
        $this->assertSame('5000000.00', $aging['current']);
        $this->assertSame('3000000.00', $aging['1_30']);
        $this->assertSame('2000000.00', $aging['31_60']);
        $this->assertSame('0.00', $aging['61_90']);
        $this->assertSame('0.00', $aging['over_90']);
        $this->assertSame('10000000.00', $aging['total']);

        // 2. Verifikasi Customer Statement untuk 30 hari terakhir
        $statement = $this->arService->generateCustomerStatement(
            customerId: (int) $this->customer->id,
            startDate: Carbon::today()->subDays(30),
            endDate: Carbon::today()
        );

        // Saldo awal berasal dari Inv 1 (60 hari lalu): 2.000.000
        $this->assertSame('2000000.00', $statement['opening_balance']);
        // Mutasi selama periode adalah Inv 2 (3jt) dan Inv 3 (5jt)
        $this->assertCount(2, $statement['transactions']);
        // Saldo akhir: 2jt + 3jt + 5jt = 10.000.000
        $this->assertSame('10000000.00', $statement['closing_balance']);
    }
}
