<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Integration\Models\WebhookSubscription;
use App\Modules\Integration\Services\SanctumScopeGuardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookSubscriptionApiController extends Controller
{
    public function __construct(
        protected SanctumScopeGuardService $guard
    ) {}

    /**
     * Menampilkan daftar langganan webhook aktif tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $this->guard->authorize($request, 'webhooks:manage');

        /** @var User $user */
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $subs = WebhookSubscription::where('tenant_id', $tenantId)
            ->latest('id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $subs,
        ]);
    }

    /**
     * Mendaftarkan webhook subscription baru untuk integrasi n8n / pihak ketiga.
     */
    public function store(Request $request): JsonResponse
    {
        $this->guard->authorize($request, 'webhooks:manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'target_url' => ['required', 'url', 'max:512'],
            'subscribed_events' => ['required', 'array', 'min:1'],
            'subscribed_events.*' => ['required', 'string'],
            'secret_key' => ['nullable', 'string', 'min:16', 'max:128'],
            'store_id' => ['nullable', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $secret = $validated['secret_key'] ?? ('whsec_' . Str::random(32));

        $sub = WebhookSubscription::create([
            'tenant_id' => $tenantId,
            'store_id' => $validated['store_id'] ?? null,
            'name' => $validated['name'],
            'target_url' => $validated['target_url'],
            'secret_key' => $secret,
            'subscribed_events' => $validated['subscribed_events'],
            'is_active' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $sub,
        ], 201);
    }

    /**
     * Menghapus langganan webhook.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->guard->authorize($request, 'webhooks:manage');

        /** @var User $user */
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $sub = WebhookSubscription::where('tenant_id', $tenantId)->findOrFail($id);
        $sub->delete();

        return response()->json([
            'status' => 'success',
            'message' => "Webhook subscription #{$id} successfully deleted.",
        ]);
    }
}
