<?php

namespace App\Modules\Reports\Controllers;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreTarget;
use App\Models\User;
use App\Modules\Reports\Services\CashierWorkspaceService;
use App\Modules\Reports\Services\DashboardService;
use App\Modules\Reports\Services\ExecutiveDashboardService;
use App\Modules\Reports\Services\ManagerDashboardService;
use App\Modules\Sales\Services\TransactionService;
use App\Modules\Users\Services\CashierPinService;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * DashboardController: Mengumpulkan ringkasan data statistik sistem
 * untuk dirender pada Beranda Admin & Workspace Operasional (SAP Fiori / Odoo style).
 */
class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService,
        protected ExecutiveDashboardService $executiveDashboardService,
        protected ManagerDashboardService $managerDashboardService,
        protected CashierWorkspaceService $cashierWorkspaceService,
        protected TransactionService $transactionService,
        protected CashierPinService $cashierPinService,
        protected AuditTrailService $auditTrailService,
        protected TenantContext $tenantContext,
    ) {}

    /**
     * Mengkalkulasi pendapatan dan statistik sadar-peran, lalu merendernya dalam View.
     */
    public function index(Request $request): View
    {
        /** @var User|null $user */
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        $tenantId = $this->tenantContext->requireTenantId();

        // 1. Resolusi Dynamic RBAC Workspace Archetype
        $userArchetype = $user->getDashboardArchetype();
        $canSwitchArchetype = $user->hasPermission('*')
            || (bool) ($user->role->is_admin ?? false)
            || $user->canViewConsolidatedStores();

        $activeArchetype = ($canSwitchArchetype && $request->filled('view'))
            ? (string) $request->query('view')
            : $userArchetype;

        // 2. Ambil metrik dasar (DashboardService) untuk kompatibilitas penuh komponen yang ada
        $metrics = $this->dashboardService->getDashboardMetrics();

        // 3. Konteks filter 5-tier
        $storeId = $request->filled('store_id') && is_numeric($request->query('store_id'))
            ? (int) $request->query('store_id')
            : null;
        $companyId = $request->filled('company_id') && is_numeric($request->query('company_id'))
            ? (int) $request->query('company_id')
            : null;
        $period = (string) ($request->query('period') ?? '30d');

        // 4. Pengambilan data spesifik archetype domain services
        $executiveData = $this->executiveDashboardService->getMetrics($user, $period, $storeId, $companyId);
        $managerData = $this->managerDashboardService->getMetrics($user, $storeId);
        $cashierData = $this->cashierWorkspaceService->getWorkspaceData($user);

        // 5. Daftar cabang aktif untuk modal target cabang
        $availableStores = Store::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('dashboard', array_merge($metrics, [
            'activeArchetype' => $activeArchetype,
            'canSwitchArchetype' => $canSwitchArchetype,
            'executiveData' => $executiveData,
            'managerData' => $managerData,
            'cashierData' => $cashierData,
            'availableStores' => $availableStores,
        ]));
    }

    /**
     * Memproses persetujuan/penolakan tiket operasional (Void Order, Selisih Kas Shift, Diskon Manual)
     * melalui verifikasi otorisasi PIN Supervisor.
     */
    public function approveTicket(Request $request): JsonResponse|RedirectResponse
    {
        /** @var User|null $user */
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'ticket_id' => 'required|integer',
            'ticket_type' => 'required|string|in:VOID,CASH_VARIANCE,DISCOUNT_OVERRIDE',
            'decision' => 'required|string|in:approve,reject',
            'supervisor_pin' => 'required|string|min:4|max:6',
            'reason' => 'nullable|string|max:255',
        ]);

        $tenantId = $this->tenantContext->requireTenantId();
        $pin = (string) $validated['supervisor_pin'];
        $decision = (string) $validated['decision'];
        $ticketId = (int) $validated['ticket_id'];
        $ticketType = (string) $validated['ticket_type'];
        $reason = trim((string) ($validated['reason'] ?? ''));

        // 1. Validasi Otoritas & Verifikasi PIN Supervisor
        $authorizerUser = null;

        $hasApprovalAuthority = $user->hasPermission('dashboard.action.approve_void_discount')
            || $user->hasPermission('*')
            || in_array($user->role?->name, ['Owner', 'Admin', 'Manager', 'Supervisor'], true);

        if ($hasApprovalAuthority && $this->cashierPinService->hasPin($user) && $this->cashierPinService->verifyPin($user, $pin)) {
            $authorizerUser = $user;
        }

        if (! $authorizerUser) {
            // Evaluasi apakah PIN cocok dengan supervisor berwenang lain di tenant yang sama
            // Gunakan Hash::check langsung untuk menghindari false-positive lockout pada supervisor lain
            $supervisors = User::withoutGlobalScopes()
                ->with('role')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereNotNull('pin_hash')
                ->get();

            foreach ($supervisors as $supervisor) {
                $isSuperAuthorized = $supervisor->hasPermission('dashboard.action.approve_void_discount')
                    || $supervisor->hasPermission('*')
                    || in_array($supervisor->role?->name, ['Owner', 'Admin', 'Manager', 'Supervisor'], true);

                if ($isSuperAuthorized && Hash::check($pin, (string) $supervisor->pin_hash)) {
                    if ($this->cashierPinService->isLockedOut($supervisor)) {
                        break;
                    }
                    $this->cashierPinService->resetPinLockout($supervisor);
                    $authorizerUser = $supervisor;
                    break;
                }
            }

            if (! $authorizerUser) {
                // Throttle caller untuk mencegah brute-force
                RateLimiter::hit("dashboard:supervisor_pin_attempt:{$tenantId}:{$user->id}", 900);
            }
        }

        if (! $authorizerUser) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'PIN otorisasi supervisor tidak valid atau Anda tidak memiliki izin otorisasi operasional.',
                ], 422);
            }

            return redirect()->back()->with('error', 'PIN otorisasi supervisor tidak valid atau Anda tidak memiliki izin otorisasi operasional.');
        }

        // 2. Eksekusi Atomik Perubahan Transaksi & Pencatatan Audit Log
        $affectedStoreId = null;

        DB::transaction(function () use ($tenantId, $authorizerUser, $decision, $ticketId, $ticketType, $reason, &$affectedStoreId): void {
            if ($ticketType === 'CASH_VARIANCE') {
                $shift = Shift::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->findOrFail($ticketId);

                $affectedStoreId = (int) $shift->store_id;

                if ($decision === 'approve') {
                    $shift->update([
                        'variance_approved_by' => $authorizerUser->id,
                        'variance_reason' => $reason !== '' ? $reason : ($shift->variance_reason ?: 'Otorisasi selisih kas disetujui supervisor.'),
                    ]);

                    $this->auditTrailService->log(
                        tenantId: $tenantId,
                        action: 'shift.variance_approved',
                        userId: $authorizerUser->id,
                        storeId: $shift->store_id,
                        subjectType: Shift::class,
                        subjectId: $shift->id,
                        description: 'Shift #'.$shift->id.' cash variance of Rp '.number_format(abs((float) $shift->cash_difference), 0, ',', '.')." approved by {$authorizerUser->name}.",
                        metadata: [
                            'shift_id' => $shift->id,
                            'cash_difference' => $shift->cash_difference,
                            'variance_approved_by' => $authorizerUser->id,
                            'reason' => $reason,
                        ]
                    );
                } else {
                    $shift->update([
                        'variance_reason' => 'Ditolak: '.($reason !== '' ? $reason : 'Selisih kas ditolak supervisor.'),
                    ]);

                    $this->auditTrailService->log(
                        tenantId: $tenantId,
                        action: 'shift.variance_rejected',
                        userId: $authorizerUser->id,
                        storeId: $shift->store_id,
                        subjectType: Shift::class,
                        subjectId: $shift->id,
                        description: "Shift #{$shift->id} cash variance rejected by {$authorizerUser->name}.",
                        metadata: [
                            'shift_id' => $shift->id,
                            'reason' => $reason,
                        ]
                    );
                }
            } elseif ($ticketType === 'VOID') {
                $order = Order::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->findOrFail($ticketId);

                $affectedStoreId = (int) $order->store_id;

                if ($decision === 'approve') {
                    if ($order->order_status === OrderStatus::Paid) {
                        $this->transactionService->voidOrder($order, $reason !== '' ? $reason : 'Void approved by supervisor via Dashboard', $authorizerUser->id);
                    } else {
                        $order->forceFill([
                            'order_status' => OrderStatus::Void->value,
                            'voided_by' => $authorizerUser->id,
                            'voided_at' => now(),
                            'void_reason' => $reason !== '' ? $reason : 'Void approved by supervisor via Dashboard',
                        ])->save();
                    }

                    $this->auditTrailService->log(
                        tenantId: $tenantId,
                        action: 'order.void_approved',
                        userId: $authorizerUser->id,
                        storeId: $order->store_id,
                        subjectType: Order::class,
                        subjectId: $order->id,
                        description: "Order #{$order->order_code} void approved by {$authorizerUser->name}.",
                        metadata: [
                            'order_id' => $order->id,
                            'order_code' => $order->order_code,
                            'amount' => $order->order_amount,
                            'voided_by' => $authorizerUser->id,
                            'reason' => $reason,
                        ]
                    );
                } else {
                    $order->forceFill([
                        'void_reason' => 'Ditolak: '.($reason !== '' ? $reason : 'Void request rejected by supervisor via Dashboard'),
                    ])->save();

                    $this->auditTrailService->log(
                        tenantId: $tenantId,
                        action: 'order.void_rejected',
                        userId: $authorizerUser->id,
                        storeId: $order->store_id,
                        subjectType: Order::class,
                        subjectId: $order->id,
                        description: "Order #{$order->order_code} void request rejected by {$authorizerUser->name}.",
                        metadata: [
                            'order_id' => $order->id,
                            'order_code' => $order->order_code,
                            'amount' => $order->order_amount,
                            'rejected_by' => $authorizerUser->id,
                            'reason' => $reason,
                        ]
                    );
                }
            } elseif ($ticketType === 'DISCOUNT_OVERRIDE') {
                $this->auditTrailService->log(
                    tenantId: $tenantId,
                    action: $decision === 'approve' ? 'pos.discount_override_approved' : 'pos.discount_override_rejected',
                    userId: $authorizerUser->id,
                    description: "Manual discount override {$decision}d by {$authorizerUser->name}.",
                    metadata: [
                        'ticket_id' => $ticketId,
                        'reason' => $reason,
                    ]
                );
            }

            // 3. Invalidation cache Redis dasbor
            $this->dashboardService->invalidateCache($tenantId, $affectedStoreId);
        });

        $message = $decision === 'approve'
            ? 'Otorisasi berhasil diverifikasi dan disetujui.'
            : 'Permintaan otorisasi telah ditolak.';

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
            ]);
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * Menyimpan atau memperbarui target cabang (StoreTarget) untuk Plan vs Actual.
     */
    public function saveStoreTarget(Request $request): JsonResponse|RedirectResponse
    {
        /** @var User|null $user */
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        $canManageTargets = $user->hasPermission('*')
            || (bool) ($user->role->is_admin ?? false)
            || in_array($user->role?->name, ['Owner', 'Admin'], true)
            || $user->hasPermission('stores.manage');

        abort_unless($canManageTargets, 403, 'Anda tidak memiliki hak akses untuk mengatur target cabang.');

        $validated = $request->validate([
            'store_id' => 'required|integer',
            'target_period' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'target_net_sales' => 'required|numeric|min:0',
            'target_gross_margin_pct' => 'nullable|numeric|between:0,100',
            'max_shrinkage_pct' => 'nullable|numeric|between:0,100',
            'max_labor_cost_pct' => 'nullable|numeric|between:0,100',
        ]);

        $tenantId = $this->tenantContext->requireTenantId();

        $store = Store::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail((int) $validated['store_id']);

        $targetPeriod = ! empty($validated['target_period'])
            ? (string) $validated['target_period']
            : now()->setTimezone('Asia/Jakarta')->format('Y-m');

        StoreTarget::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'store_id' => $store->id,
                'target_period' => $targetPeriod,
            ],
            [
                'company_id' => $store->company_id,
                'target_net_sales' => (string) $validated['target_net_sales'],
                'target_gross_margin_pct' => (string) ($validated['target_gross_margin_pct'] ?? 0),
                'max_shrinkage_pct' => (string) ($validated['max_shrinkage_pct'] ?? 0),
                'max_labor_cost_pct' => (string) ($validated['max_labor_cost_pct'] ?? 0),
            ]
        );

        $this->dashboardService->invalidateCache($tenantId, (int) $store->id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Target bulanan untuk cabang {$store->name} ({$targetPeriod}) berhasil disimpan.",
            ]);
        }

        return redirect()->back()->with('success', "Target bulanan untuk cabang {$store->name} ({$targetPeriod}) berhasil disimpan.");
    }
}


