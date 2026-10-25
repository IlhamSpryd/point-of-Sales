<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Enums\ApprovalStatusEnum;
use App\Enums\PurchaseRequestFulfillmentStatusEnum;
use App\Enums\PurchaseRequestStatusEnum;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseApprovalLog;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\PurchaseApprovalService;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseRequestAndApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;

    private Store $storeCentral;

    private User $ownerUser;

    private User $managerUser;

    private User $staffUser;

    private Product $product;

    private Ingredient $ingredient;

    private Supplier $supplier;

    private PurchaseRequestService $prService;

    private PurchaseApprovalService $approvalService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Resto Sentosa',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->storeCentral = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Resto Sentosa Pusat',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setStoreId($this->storeCentral->id);

        $ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'display_name' => 'Pemilik',
            'permissions' => ['*'],
        ]);

        $managerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager',
            'display_name' => 'Manajer Operasional',
            'permissions' => ['purchasing.pr.*'],
        ]);

        $staffRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Inventory',
            'display_name' => 'Staf Gudang',
            'permissions' => ['purchasing.pr.view', 'purchasing.pr.create', 'purchasing.pr.update', 'purchasing.pr.submit', 'purchasing.pr.cancel'],
        ]);

        $this->ownerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hendra Owner',
            'email' => 'owner@sentosa.test',
            'password' => bcrypt('password123'),
            'role_id' => $ownerRole->id,
            'is_active' => true,
        ]);

        $this->managerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Megawati Manager',
            'email' => 'manager@sentosa.test',
            'password' => bcrypt('password123'),
            'role_id' => $managerRole->id,
            'is_active' => true,
        ]);
        $this->managerUser->stores()->attach($this->storeCentral->id, ['tenant_id' => $this->tenant->id]);

        $this->staffUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Santoso Staf',
            'email' => 'staff@sentosa.test',
            'password' => bcrypt('password123'),
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);
        $this->staffUser->stores()->attach($this->storeCentral->id, ['tenant_id' => $this->tenant->id]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Bahan Baku & Minuman',
            'slug' => 'bahan-baku-minuman',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Teh Melati Kotak',
            'sku' => 'SKU-TMK-01',
            'cost_price' => 5000,
            'selling_price' => 8000,
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->ingredient = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Gula Pasir Kristal',
            'unit' => 'kg',
            'cost_per_unit' => 14000.00,
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-0001',
            'name' => 'PT Pangan Perkasa',
            'status' => 'active',
        ]);

        $this->prService = app(PurchaseRequestService::class);
        $this->approvalService = app(PurchaseApprovalService::class);
    }

    public function test_pr_creation_with_calculated_totals_and_draft_status(): void
    {
        $pr = $this->prService->createPurchaseRequest(
            [
                'store_id' => $this->storeCentral->id,
                'required_by_date' => now()->addDays(5)->toDateString(),
                'notes' => 'Restock mingguan cabang pusat.',
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'requested_qty' => '10.0000',
                    'uom' => 'KARTON',
                    'estimated_unit_price' => '120000.0000',
                    'preferred_supplier_id' => $this->supplier->id,
                ],
                [
                    'purchasable_type' => 'ingredient',
                    'purchasable_id' => $this->ingredient->id,
                    'requested_qty' => '50.0000',
                    'uom' => 'KG',
                    'estimated_unit_price' => '15000.0000',
                    'preferred_supplier_id' => $this->supplier->id,
                ],
            ],
            $this->staffUser
        );

        $this->assertInstanceOf(PurchaseRequest::class, $pr);
        $expectedPrNumber = 'PR-'.$this->storeCentral->id.'-'.now()->format('Ym').'-0001';
        $this->assertSame($expectedPrNumber, $pr->pr_number);
        $this->assertSame(PurchaseRequestStatusEnum::Draft, $pr->status);
        $this->assertSame(PurchaseRequestFulfillmentStatusEnum::Unfulfilled, $pr->fulfillment_status);
        $this->assertTrue($pr->isDraft());
        $this->assertFalse($pr->isImmutable());

        // 10 x 120,000 = 1,200,000 + 50 x 15,000 = 750,000 -> Total = 1,950,000.00
        $this->assertSame('1950000.00', $pr->estimated_total_amount);
        $this->assertCount(2, $pr->lines);
    }

    public function test_pr_immutability_after_submission(): void
    {
        $pr = $this->prService->createPurchaseRequest(
            ['store_id' => $this->storeCentral->id],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'requested_qty' => '5.0000',
                    'uom' => 'PCS',
                    'estimated_unit_price' => '10000.0000',
                ],
            ],
            $this->staffUser
        );

        $this->prService->submitPurchaseRequest($pr, $this->staffUser);
        $this->assertTrue($pr->fresh()->isImmutable());

        // Upaya update setelah submitted harus ditolak dengan ValidationException
        $this->expectException(ValidationException::class);
        $this->prService->updatePurchaseRequest(
            $pr->fresh(),
            ['notes' => 'Coba ubah catatan setelah submit'],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'requested_qty' => '999.0000',
                    'uom' => 'PCS',
                    'estimated_unit_price' => '10000.0000',
                ],
            ],
            $this->staffUser
        );
    }

    public function test_auto_approval_when_no_rules_match(): void
    {
        // Tanpa ada purchase_approval_rules, PR langsung disetujui otomatis saat submit
        $pr = $this->prService->createPurchaseRequest(
            ['store_id' => $this->storeCentral->id],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'requested_qty' => '2.0000',
                    'uom' => 'PCS',
                    'estimated_unit_price' => '50000.0000',
                ],
            ],
            $this->staffUser
        );

        $submitted = $this->prService->submitPurchaseRequest($pr, $this->staffUser);

        $this->assertSame(PurchaseRequestStatusEnum::Approved, $submitted->status);
        $this->assertNotNull($submitted->approved_at);
        $this->assertSame('2.0000', $submitted->lines->first()->approved_qty);
    }

    public function test_tiered_sequential_approval_workflow_with_threshold_rules(): void
    {
        $managerRole = Role::query()->where('tenant_id', $this->tenant->id)->where('name', 'Manager')->firstOrFail();
        $ownerRole = Role::query()->where('tenant_id', $this->tenant->id)->where('name', 'Owner')->firstOrFail();

        // Rule 1: Step 1 (Manager menyetujui semua nilai >= 0)
        $this->approvalService->createOrUpdateRule([
            'document_type' => 'PR',
            'min_amount' => '0.00',
            'step_order' => 1,
            'approver_role_id' => $managerRole->id,
        ], $this->ownerUser);

        // Rule 2: Step 2 (Owner menyetujui jika nilai >= 5,000,000)
        $this->approvalService->createOrUpdateRule([
            'document_type' => 'PR',
            'min_amount' => '5000000.00',
            'step_order' => 2,
            'approver_role_id' => $ownerRole->id,
        ], $this->ownerUser);

        // PR bernilai 10,000,000 (akan melalui 2 jenjang: Manager lalu Owner)
        $prHigh = $this->prService->createPurchaseRequest(
            ['store_id' => $this->storeCentral->id],
            [
                [
                    'purchasable_type' => 'ingredient',
                    'purchasable_id' => $this->ingredient->id,
                    'requested_qty' => '1000.0000',
                    'uom' => 'KG',
                    'estimated_unit_price' => '10000.0000', // 10,000,000
                ],
            ],
            $this->staffUser
        );

        $submitted = $this->prService->submitPurchaseRequest($prHigh, $this->staffUser);
        $this->assertSame(PurchaseRequestStatusEnum::Submitted, $submitted->status);

        $approvalReq = $submitted->approvalRequests()->firstOrFail();
        $this->assertSame(1, $approvalReq->step_order);
        $this->assertSame(ApprovalStatusEnum::Pending, $approvalReq->status);

        // Step 1: Manager menyetujui
        $this->approvalService->approve($approvalReq, $this->managerUser, 'Disetujui manajer, teruskan ke owner.');

        $approvalReq->refresh();
        $submitted->refresh();
        // Karena ada step 2 (Owner), status PR masih submitted dan request maju ke step 2
        $this->assertSame(2, $approvalReq->step_order);
        $this->assertSame(ApprovalStatusEnum::Pending, $approvalReq->status);
        $this->assertSame(PurchaseRequestStatusEnum::Submitted, $submitted->status);

        // Step 2: Owner menyetujui
        $this->approvalService->approve($approvalReq, $this->ownerUser, 'Disetujui owner final.');

        $approvalReq->refresh();
        $submitted->refresh();
        $this->assertSame(ApprovalStatusEnum::Approved, $approvalReq->status);
        $this->assertSame(PurchaseRequestStatusEnum::Approved, $submitted->status);
        $this->assertNotNull($submitted->approved_at);
        $this->assertSame($this->ownerUser->id, $submitted->approved_by_user_id);
    }

    public function test_rejection_workflow_and_reopening(): void
    {
        $managerRole = Role::query()->where('tenant_id', $this->tenant->id)->where('name', 'Manager')->firstOrFail();

        $this->approvalService->createOrUpdateRule([
            'document_type' => 'PR',
            'min_amount' => '0.00',
            'step_order' => 1,
            'approver_role_id' => $managerRole->id,
        ], $this->ownerUser);

        $pr = $this->prService->createPurchaseRequest(
            ['store_id' => $this->storeCentral->id],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'requested_qty' => '5.0000',
                    'uom' => 'PCS',
                    'estimated_unit_price' => '20000.0000',
                ],
            ],
            $this->staffUser
        );

        $submitted = $this->prService->submitPurchaseRequest($pr, $this->staffUser);
        $req = $submitted->approvalRequests()->firstOrFail();

        // Penolakan dengan alasan < 10 karakter harus gagal
        try {
            $this->approvalService->reject($req, $this->managerUser, 'Ditolak');
            $this->fail('Harus gagal validasi alasan penolakan pendek.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->validator->getMessageBag()->toArray());
        }

        // Penolakan dengan alasan valid (>= 10 karakter)
        $this->approvalService->reject($req, $this->managerUser, 'Stok gudang masih sangat mencukupi untuk 2 minggu.');

        $submitted->refresh();
        $req->refresh();
        $this->assertSame(ApprovalStatusEnum::Rejected, $req->status);
        $this->assertSame(PurchaseRequestStatusEnum::Rejected, $submitted->status);
        $this->assertSame('Stok gudang masih sangat mencukupi untuk 2 minggu.', $submitted->rejection_reason);

        // Aksi Reopen: ubah kembali ke draf agar staf dapat merevisi
        $reopened = $this->prService->reopenRejectedPurchaseRequest($submitted, $this->staffUser);
        $this->assertSame(PurchaseRequestStatusEnum::Draft, $reopened->status);
        $this->assertTrue($reopened->isDraft());
    }

    public function test_separation_of_duties_prevents_self_approval(): void
    {
        $managerRole = Role::query()->where('tenant_id', $this->tenant->id)->where('name', 'Manager')->firstOrFail();

        $this->approvalService->createOrUpdateRule([
            'document_type' => 'PR',
            'min_amount' => '0.00',
            'step_order' => 1,
            'approver_role_id' => $managerRole->id,
        ], $this->ownerUser);

        // Manager membuat dan mengajukan PR
        $pr = $this->prService->createPurchaseRequest(
            ['store_id' => $this->storeCentral->id],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'requested_qty' => '1.0000',
                    'uom' => 'PCS',
                    'estimated_unit_price' => '10000.0000',
                ],
            ],
            $this->managerUser
        );

        $submitted = $this->prService->submitPurchaseRequest($pr, $this->managerUser);
        $req = $submitted->approvalRequests()->firstOrFail();

        // Manager mencoba menyetujui PR yang diajukan sendiri -> Wajib Ditolak SoD
        $this->expectException(ValidationException::class);
        $this->approvalService->approve($req, $this->managerUser, 'Mencoba self approval');
    }

    public function test_approval_logs_are_immutable_and_append_only(): void
    {
        $managerRole = Role::query()->where('tenant_id', $this->tenant->id)->where('name', 'Manager')->firstOrFail();

        $this->approvalService->createOrUpdateRule([
            'document_type' => 'PR',
            'min_amount' => '0.00',
            'step_order' => 1,
            'approver_role_id' => $managerRole->id,
        ], $this->ownerUser);

        $pr = $this->prService->createPurchaseRequest(
            ['store_id' => $this->storeCentral->id],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'requested_qty' => '1.0000',
                    'uom' => 'PCS',
                    'estimated_unit_price' => '10000.0000',
                ],
            ],
            $this->staffUser
        );

        $this->prService->submitPurchaseRequest($pr, $this->staffUser);

        $log = PurchaseApprovalLog::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame('submitted', $log->action);

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // Trigger MySQL trg_purchase_approval_logs_before_update harus menggagalkan UPDATE
            $this->expectException(QueryException::class);
            DB::table('purchase_approval_logs')
                ->where('id', $log->id)
                ->update(['reason' => 'Perubahan manipulatif']);
        }
    }
}
