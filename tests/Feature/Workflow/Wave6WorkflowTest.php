<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Core\Context\TenantContext;
use App\Enums\ApprovalStatusEnum;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseApprovalLog;
use App\Modules\Purchasing\Models\PurchaseApprovalRequest;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use App\Modules\Workflow\Exceptions\SelfApprovalForbiddenException;
use App\Modules\Workflow\Services\ApprovalEngineService;
use App\Modules\Workflow\Services\SegregationOfDutiesGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Wave6WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $branchManager;

    private User $areaManager;

    private User $ownerUser;

    private ApprovalEngineService $approvalEngine;

    private PurchaseRequestService $prService;

    private Product $product;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Resto Wave 6 WFL Group',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Tebet Jakarta',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $branchManagerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch Manager',
            'is_admin' => false,
            'permissions' => ['purchasing.request.approve_l1'],
        ]);

        $areaManagerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Area Manager',
            'is_admin' => false,
            'permissions' => ['purchasing.request.approve_l2'],
        ]);

        $ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'is_admin' => true,
            'permissions' => ['*'],
        ]);

        $this->branchManager = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $branchManagerRole->id,
            'name' => 'Bambang Branch Manager',
            'email' => 'bambang.bm@wave6.test',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->areaManager = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $areaManagerRole->id,
            'name' => 'Andi Area Manager',
            'email' => 'andi.am@wave6.test',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->ownerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'name' => 'Hartono Owner',
            'email' => 'hartono.owner@wave6.test',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $cat = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Bahan Baku',
            'slug' => 'bahan-baku',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $cat->id,
            'name' => 'Minyak Goreng Sawit 20L',
            'sku' => 'PRD-MGO-20L',
            'price' => '300000.00',
        ]);

        $this->approvalEngine = app(ApprovalEngineService::class);
        $this->prService = app(PurchaseRequestService::class);
    }

    /**
     * T-WFL-01: Purchase Request senilai Rp 10.000.000 otomatis dialihkan ke antrean persetujuan Level 2 (Area Manager)
     * sesuai konfigurasi matriks nominal.
     */
    public function test_t_wfl_01_purchase_request_ten_million_is_routed_to_level_2_area_manager(): void
    {
        // 1. Buat Purchase Request senilai Rp 10.000.000
        $pr = $this->prService->createPurchaseRequest(
            ['store_id' => $this->store->id],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'requested_qty' => '25.0000',
                    'uom' => 'JRG',
                    'estimated_unit_price' => '400000.0000', // Total 25 * 400.000 = 10.000.000
                ],
            ],
            $this->branchManager
        );

        $this->assertEquals('10000000.00', (string) $pr->estimated_total_amount);

        // 2. Evaluasi matriks level persetujuan
        $level = $this->approvalEngine->resolveApprovalLevel((string) $pr->estimated_total_amount);
        $this->assertEquals(2, $level, 'Nominal Rp 10.000.000 wajib masuk ke tingkat persetujuan Level 2 (Area Manager).');
        $this->assertEquals('Area Manager', $this->approvalEngine->resolveRoleNameForLevel($level));

        // 3. Ajukan dokumen melalui ApprovalEngineService
        $request = $this->approvalEngine->submitRequest(
            approvable: $pr,
            requester: $this->branchManager,
            amount: (string) $pr->estimated_total_amount,
            storeId: $this->store->id,
            documentType: 'PR'
        );

        $this->assertInstanceOf(PurchaseApprovalRequest::class, $request);
        $this->assertEquals(2, $request->step_order, 'Permintaan persetujuan wajib berada di antrean step_order = 2.');
        $this->assertEquals(ApprovalStatusEnum::Pending, $request->status);
        $this->assertEquals($this->branchManager->id, $request->submitted_by_user_id);

        // 4. Verifikasi pencatatan log audit append-only
        $log = PurchaseApprovalLog::withoutGlobalScopes()
            ->where('approval_request_id', $request->id)
            ->where('action', 'submitted')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(2, $log->step_order);
        $this->assertStringContainsString('Level 2', (string) $log->reason);
        $this->assertEquals($this->branchManager->id, $log->actor_id);

        // 5. Pejabat berwenang (Area Manager) dapat menyetujui
        $approved = $this->approvalEngine->approve($request, $this->areaManager, 'Disetujui untuk pemenuhan persediaan kuartal 4.');
        $this->assertTrue($approved);

        $request->refresh();
        $this->assertEquals(ApprovalStatusEnum::Approved, $request->status);
        $this->assertEquals($this->areaManager->id, $request->approved_by_user_id);
    }

    /**
     * T-WFL-02: Upaya manajer menyetujui pengajuannya sendiri otomatis digagalkan dengan SelfApprovalForbiddenException.
     */
    public function test_t_wfl_02_manager_self_approval_is_prevented_with_self_approval_forbidden_exception(): void
    {
        // 1. Manajer membuat pengajuan pengadaan senilai Rp 3.000.000 (Level 1: Branch Manager)
        $pr = $this->prService->createPurchaseRequest(
            ['store_id' => $this->store->id],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'requested_qty' => '10.0000',
                    'uom' => 'JRG',
                    'estimated_unit_price' => '300000.0000', // Total Rp 3.000.000
                ],
            ],
            $this->branchManager
        );

        $request = $this->approvalEngine->submitRequest(
            approvable: $pr,
            requester: $this->branchManager,
            amount: '3000000.00',
            storeId: $this->store->id,
            documentType: 'PR'
        );

        $this->assertEquals(1, $request->step_order);
        $this->assertEquals($this->branchManager->id, $request->submitted_by_user_id);

        // 2. Verifikasi langsung SegregationOfDutiesGuard
        $guard = app(SegregationOfDutiesGuard::class);
        $this->expectException(SelfApprovalForbiddenException::class);
        $guard->assertCanApprove($this->branchManager->id, $this->branchManager->id);

        // 3. Upaya approve() dengan requester yang sama wajib melempar SelfApprovalForbiddenException
        $this->approvalEngine->approve($request, $this->branchManager, 'Mencoba menyetujui sendiri');
    }

    /**
     * Verifikasi penolakan persetujuan mewajibkan alasan penolakan dan mencatat log audit append-only.
     */
    public function test_rejection_requires_reason_and_logs_audit_trail(): void
    {
        $pr = $this->prService->createPurchaseRequest(
            ['store_id' => $this->store->id],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'requested_qty' => '5.0000',
                    'uom' => 'JRG',
                    'estimated_unit_price' => '300000.0000',
                ],
            ],
            $this->branchManager
        );

        $request = $this->approvalEngine->submitRequest(
            approvable: $pr,
            requester: $this->branchManager,
            amount: '1500000.00',
            storeId: $this->store->id,
            documentType: 'PR'
        );

        // Penolakan tanpa alasan harus gagal validasi
        try {
            $this->approvalEngine->reject($request, $this->areaManager, '');
            $this->fail('Harus melempar ValidationException jika alasan penolakan kosong.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        // Penolakan dengan alasan yang sah
        $reason = 'Anggaran persediaan cabang telah dialihkan untuk renovasi dapur.';
        $rejected = $this->approvalEngine->reject($request, $this->areaManager, $reason);
        $this->assertTrue($rejected);

        $request->refresh();
        $this->assertEquals(ApprovalStatusEnum::Rejected, $request->status);
        $this->assertEquals($reason, $request->rejection_reason);

        // Periksa log audit penolakan
        $rejectLog = PurchaseApprovalLog::withoutGlobalScopes()
            ->where('approval_request_id', $request->id)
            ->where('action', 'rejected')
            ->first();

        $this->assertNotNull($rejectLog);
        $this->assertEquals($reason, $rejectLog->reason);
        $this->assertEquals($this->areaManager->id, $rejectLog->actor_id);
    }
}
