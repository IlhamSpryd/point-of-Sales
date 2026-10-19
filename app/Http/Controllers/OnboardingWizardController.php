<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBusinessProfileRequest;
use App\Http\Requests\StoreInitialBranchRequest;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use App\Services\TenantOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OnboardingWizardController extends Controller
{
    public function __construct(
        private TenantOnboardingService $onboardingService,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Langkah 1: Form Profil Bisnis & Standar Operasional.
     */
    public function businessProfile(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;

        if ($tenant === null) {
            abort(403, 'Tenant workspace not found.');
        }

        // Jika onboarding sudah selesai, langsung arahkan ke dashboard
        if ($tenant->isOnboardingCompleted()) {
            return redirect()->route('dashboard');
        }

        $businessCategory = Setting::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('key', 'business_category')
            ->value('value') ?? 'fnb_cafe';

        $tenantTimezone = Setting::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('key', 'timezone')
            ->value('value') ?? 'Asia/Jakarta';

        $defaultCurrency = Setting::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('key', 'currency_code')
            ->value('value') ?? 'IDR';

        $ownerPhone = Setting::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('key', 'business_phone')
            ->value('value') ?? $user->phone_number;

        return view('onboarding.business-profile', [
            'tenant' => $tenant,
            'user' => $user,
            'businessName' => $tenant->name,
            'businessCategory' => $businessCategory,
            'countryCode' => $tenant->country_code ?? 'ID',
            'tenantTimezone' => $tenantTimezone,
            'defaultCurrency' => $defaultCurrency,
            'ownerPhone' => $ownerPhone,
        ]);
    }

    /**
     * Simpan Langkah 1 dan lanjut ke Langkah 2.
     */
    public function storeBusinessProfile(StoreBusinessProfileRequest $request): RedirectResponse
    {
        $tenant = $request->user()->tenant;

        $this->onboardingService->saveBusinessProfile($tenant, $request->validated());

        return redirect()->route('onboarding.initial-branch')
            ->with('success', 'Business profile saved. Now, configure your primary branch.');
    }

    /**
     * Langkah 2: Form Pengaturan Cabang Pertama.
     */
    public function initialBranch(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;

        if ($tenant === null) {
            abort(403, 'Tenant workspace not found.');
        }

        if ($tenant->isOnboardingCompleted()) {
            return redirect()->route('dashboard');
        }

        $initialStore = $this->onboardingService->getInitialStore($tenant);

        $tenantTimezone = Setting::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('key', 'timezone')
            ->value('value') ?? 'Asia/Jakarta';

        return view('onboarding.initial-branch', [
            'tenant' => $tenant,
            'store' => $initialStore,
            'storeName' => $initialStore->name ?: ($tenant->name.' - Main Branch'),
            'storeCode' => $initialStore->code ?: 'MAIN-01',
            'storeTimezone' => $initialStore->timezone ?: $tenantTimezone,
            'businessDateCutoff' => $initialStore->business_date_cutoff ?: '00:00',
            'defaultChannel' => $initialStore->default_channel ?: 'dine_in',
            'storeAddress' => $initialStore->address,
            'storePhone' => $initialStore->phone,
        ]);
    }

    /**
     * Simpan Langkah 2, selesaikan onboarding tenant, dan arahkan ke Dashboard.
     */
    public function storeInitialBranch(StoreInitialBranchRequest $request): RedirectResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;

        $store = $this->onboardingService->saveInitialBranch($tenant, $request->validated(), $user);

        // Pasang active_store_id ke sesi
        $request->session()->put('active_store_id', $store->id);
        $this->tenantContext->setStoreId($store->id);

        return redirect()->route('dashboard')
            ->with('success', 'Your business workspace and primary branch are ready for operation.');
    }
}
