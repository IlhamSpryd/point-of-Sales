<?php

declare(strict_types=1);

namespace App\Livewire\Integration;

use App\Core\Context\TenantContext;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\Integration\Models\OfdChannelConfig;
use App\Modules\Pricing\Models\PriceList;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class OfdChannelConfigManager extends Component
{
    use RequiresTenantContext;

    public string $provider = 'grabfood';

    public string $merchantId = '';

    public ?string $clientId = null;

    public ?string $clientSecret = null;

    public ?string $signingSecret = null;

    public bool $hasExistingSecret = false;

    public float $commissionRatePct = 20.00;

    public float $priceMarkupPct = 0.00;

    public ?int $priceListId = null;

    public bool $autoAcceptOrder = false;

    public bool $isActive = true;

    public ?int $activeStoreId = null;

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $role = Auth::user()?->role;
        $roleName = $role?->name;
        if (! $role?->is_admin && ! in_array($roleName, ['Owner', 'Manager', 'Admin'], true)) {
            abort(403, 'You do not have permission to manage OFD channel configurations.');
        }
    }

    public function mount(): void
    {
        $this->activeStoreId = app(TenantContext::class)->getStoreId() ?? (int) Auth::user()?->store_id;
        $this->loadProviderConfig();
    }

    public function setProvider(string $provider): void
    {
        if (! in_array($provider, ['grabfood', 'gofood'], true)) {
            return;
        }

        $this->provider = $provider;
        $this->loadProviderConfig();
    }

    public function loadProviderConfig(): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $storeId = $this->activeStoreId;

        $config = OfdChannelConfig::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', $this->provider)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->first();

        if ($config) {
            $this->merchantId = (string) $config->merchant_id;
            $this->clientId = $config->client_id;
            $this->commissionRatePct = (float) $config->commission_rate_pct;
            $this->priceMarkupPct = (float) $config->price_markup_pct;
            $this->priceListId = $config->price_list_id ? (int) $config->price_list_id : null;
            $this->autoAcceptOrder = (bool) $config->auto_accept_order;
            $this->isActive = (bool) $config->is_active;
            $this->hasExistingSecret = ! empty($config->signing_secret);
            $this->signingSecret = null;
            $this->clientSecret = null;
        } else {
            $this->merchantId = '';
            $this->clientId = null;
            $this->commissionRatePct = 20.00;
            $this->priceMarkupPct = 0.00;
            $this->priceListId = null;
            $this->autoAcceptOrder = false;
            $this->isActive = true;
            $this->hasExistingSecret = false;
            $this->signingSecret = null;
            $this->clientSecret = null;
        }
    }

    public function generateSigningSecret(): void
    {
        $this->signingSecret = bin2hex(random_bytes(16));
    }

    public function saveConfig(): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $storeId = $this->activeStoreId ?? app(TenantContext::class)->getStoreId();

        $rules = [
            'provider' => ['required', Rule::in(['grabfood', 'gofood'])],
            'merchantId' => ['required', 'string', 'max:100'],
            'clientId' => ['nullable', 'string', 'max:100'],
            'commissionRatePct' => ['required', 'numeric', 'min:0', 'max:100'],
            'priceMarkupPct' => ['required', 'numeric', 'min:0', 'max:100'],
            'priceListId' => ['nullable', Rule::exists('price_lists', 'id')->where('tenant_id', $tenantId)],
            'autoAcceptOrder' => ['boolean'],
            'isActive' => ['boolean'],
        ];

        if (! $this->hasExistingSecret && empty($this->signingSecret)) {
            $rules['signingSecret'] = ['required', 'string', 'min:16'];
        }

        $this->validate($rules);

        $attributes = [
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'provider' => $this->provider,
        ];

        $values = [
            'merchant_id' => $this->merchantId,
            'client_id' => $this->clientId,
            'commission_rate_pct' => $this->commissionRatePct,
            'price_markup_pct' => $this->priceMarkupPct,
            'price_list_id' => $this->priceListId,
            'auto_accept_order' => $this->autoAcceptOrder,
            'is_active' => $this->isActive,
        ];

        if (! empty($this->signingSecret)) {
            $values['signing_secret'] = $this->signingSecret;
        }
        if (! empty($this->clientSecret)) {
            $values['client_secret'] = $this->clientSecret;
        }

        OfdChannelConfig::updateOrCreate($attributes, $values);

        $this->hasExistingSecret = true;
        $this->signingSecret = null;
        $this->clientSecret = null;

        session()->flash('message', 'Konfigurasi '.strtoupper($this->provider).' berhasil disimpan.');
    }

    public function render()
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $priceLists = PriceList::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $webhookUrl = url('/api/v1/webhooks/'.$this->provider);
        $headerName = $this->provider === 'grabfood' ? 'X-Grab-Signature' : 'X-Gofood-Signature';

        return view('livewire.integration.ofd-channel-config-manager', [
            'priceLists' => $priceLists,
            'webhookUrl' => $webhookUrl,
            'headerName' => $headerName,
        ]);
    }
}
