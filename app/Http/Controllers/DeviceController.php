<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PairDeviceRequest;
use App\Http\Requests\StoreDeviceRequest;
use App\Http\Requests\UpdateDeviceRequest;
use App\Models\Device;
use App\Models\Store;
use App\Services\Context\TenantContext;
use App\Services\DeviceService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function __construct(
        protected DeviceService $deviceService
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Device::class);

        $tenantId = app(TenantContext::class)->requireTenantId();
        $devices = $this->deviceService
            ->getFilteredQuery($request)
            ->paginate(15)
            ->withQueryString();

        $stores = Store::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get(['id', 'name', 'code']);

        return view('devices.index', compact('devices', 'stores'));
    }

    public function create(): View
    {
        $this->authorize('create', Device::class);

        $tenantId = app(TenantContext::class)->requireTenantId();
        $stores = Store::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get(['id', 'name', 'code']);

        return view('devices.create', compact('stores'));
    }

    public function store(StoreDeviceRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $store = Store::withoutTenantScope()->findOrFail($validated['store_id']);

        try {
            $device = $this->deviceService->registerDevice($store, $validated);

            return redirect()
                ->route('devices.show', $device)
                ->with('success', "Perangkat '{$device->device_name}' berhasil didaftarkan. Gunakan token pairing untuk menghubungkan kasir fisik.");
        } catch (DomainException $e) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    public function show(Device $device): View
    {
        $this->authorize('view', $device);

        $device->load('store');

        return view('devices.show', compact('device'));
    }

    public function edit(Device $device): View
    {
        $this->authorize('update', $device);

        return view('devices.edit', compact('device'));
    }

    public function update(UpdateDeviceRequest $request, Device $device): RedirectResponse
    {
        $this->deviceService->update($device, $request->validated());

        return redirect()
            ->route('devices.show', $device)
            ->with('success', "Informasi perangkat '{$device->device_name}' berhasil diperbarui.");
    }

    public function destroy(Device $device): RedirectResponse
    {
        $this->authorize('delete', $device);

        $this->deviceService->deleteDevice($device);

        return redirect()
            ->route('devices.index')
            ->with('success', "Perangkat '{$device->device_name}' berhasil dihapus.");
    }

    public function revoke(Device $device): RedirectResponse
    {
        $this->authorize('revoke', $device);

        $this->deviceService->revokeDevice($device);

        return redirect()
            ->route('devices.show', $device)
            ->with('success', "Akses perangkat '{$device->device_name}' telah dicabut.");
    }

    public function regenerateToken(Device $device): RedirectResponse
    {
        $this->authorize('pair', $device);

        $this->deviceService->regeneratePairingToken($device);

        return redirect()
            ->route('devices.show', $device)
            ->with('success', 'Token pairing baru berhasil diterbitkan.');
    }

    /**
     * Endpoint API aktivasi/pairing terminal kasir fisik.
     */
    public function pair(PairDeviceRequest $request): JsonResponse
    {
        try {
            $device = $this->deviceService->pairDevice(
                pairingToken: $request->input('pairing_token'),
                fingerprint: $request->input('device_fingerprint'),
                ipAddress: $request->ip(),
                metadata: $request->input('metadata')
            );

            return response()->json([
                'success' => true,
                'message' => 'Perangkat kasir berhasil dihubungkan ke toko.',
                'device' => [
                    'id' => $device->id,
                    'code' => $device->device_code,
                    'name' => $device->device_name,
                    'type' => $device->device_type,
                    'store_id' => $device->store_id,
                    'tenant_id' => $device->tenant_id,
                    'status' => $device->status,
                ],
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
