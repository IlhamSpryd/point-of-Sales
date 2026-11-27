import { execFileSync } from 'node:child_process';

export interface DemoSnapshot {
    tenantAId: number;
    tenantBId: number;
    tenantBOrderId: number;
    tenantBOrderCode: string;
    tenantBPeriodId: number;
    jktStoreId: number;
    bdgStoreId: number;
    posDeviceId: number;
    posDevice2Id: number;
    kasirId: number;
    kasir2Id: number;
    supervisorId: number;
    ownerId: number;
    kopiSusuProductId: number;
    dripBoxProductId: number;
    tumblerProductId: number;
    airMineralProductId: number;
    coffeeIngredientId: number;
    jktDripBoxStock: number;
    jktTumblerStock: number;
    jktCoffeeIngredientStock: number;
    midtransServerKey: string;
    seededPendingQrisCode: string;
}

export interface OrderSummary {
    id: number;
    order_code: string;
    order_status: string;
    order_lifecycle_status: string;
    payment_status: string;
    fulfillment_status: string;
    order_amount: number;
    discount_amount: number;
    tax_amount: number;
    shift_id: number | null;
    offline_client_uuid: string | null;
    payment_count: number;
    loyalty_count: number;
    outbox_count: number;
    return_count: number;
    refund_count: number;
    drawer_refund_count: number;
}

function runPhpJson<T>(scriptBody: string): T {
    const bootstrap = `
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
`;
    const output = execFileSync('php', ['-r', bootstrap + '\n' + scriptBody], {
        cwd: process.cwd(),
        encoding: 'utf8',
        env: process.env,
    });
    return JSON.parse(output.trim()) as T;
}

export function getDemoSnapshot(): DemoSnapshot {
    return runPhpJson<DemoSnapshot>(`
use Illuminate\\Support\\Facades\\DB;
$tenantAId = (int) DB::table('tenants')->where('slug', 'kopi-senja')->value('id');
$tenantBId = (int) DB::table('tenants')->where('slug', 'warung-mba-rina')->value('id');
$jktStoreId = (int) DB::table('stores')->where('tenant_id', $tenantAId)->where('code', 'STR-JKT-01')->value('id');
$bdgStoreId = (int) DB::table('stores')->where('tenant_id', $tenantAId)->where('code', 'STR-BDG-02')->value('id');
$posDeviceId = (int) DB::table('devices')->where('tenant_id', $tenantAId)->where('device_code', 'POS-JKT-01')->value('id');
$posDevice2Id = (int) DB::table('devices')->where('tenant_id', $tenantAId)->where('device_code', 'POS-JKT-02')->value('id');
$kasirId = (int) DB::table('users')->where('tenant_id', $tenantAId)->where('email', 'kasir@pos.test')->value('id');
$kasir2Id = (int) DB::table('users')->where('tenant_id', $tenantAId)->where('email', 'kasir2@pos.test')->value('id');
$supervisorId = (int) DB::table('users')->where('tenant_id', $tenantAId)->where('email', 'supervisor@pos.test')->value('id');
$ownerId = (int) DB::table('users')->where('tenant_id', $tenantAId)->where('email', 'admin@pos.test')->value('id');
$kopiSusuProductId = (int) DB::table('products')->where('tenant_id', $tenantAId)->where('product_code', 'KOPI-SUSU')->value('id');
$dripBoxProductId = (int) DB::table('products')->where('tenant_id', $tenantAId)->where('product_code', 'RT-DRIPBOX')->value('id');
$tumblerProductId = (int) DB::table('products')->where('tenant_id', $tenantAId)->where('product_code', 'RT-TUMBLER')->value('id');
$airMineralProductId = (int) DB::table('products')->where('tenant_id', $tenantAId)->where('product_code', 'AIR-MINERAL')->value('id');
$coffeeIngredientId = (int) DB::table('ingredients')->where('tenant_id', $tenantAId)->where('ingredient_code', 'ING-BJI-KOPI')->value('id');
$tenantBOrder = DB::table('orders')->where('tenant_id', $tenantBId)->orderBy('id')->first(['id', 'order_code']);
$tenantBPeriodId = (int) DB::table('accounting_periods')->where('tenant_id', $tenantBId)->orderBy('id')->value('id');

echo json_encode([
    'tenantAId' => $tenantAId,
    'tenantBId' => $tenantBId,
    'tenantBOrderId' => (int) $tenantBOrder->id,
    'tenantBOrderCode' => (string) $tenantBOrder->order_code,
    'tenantBPeriodId' => $tenantBPeriodId,
    'jktStoreId' => $jktStoreId,
    'bdgStoreId' => $bdgStoreId,
    'posDeviceId' => $posDeviceId,
    'posDevice2Id' => $posDevice2Id,
    'kasirId' => $kasirId,
    'kasir2Id' => $kasir2Id,
    'supervisorId' => $supervisorId,
    'ownerId' => $ownerId,
    'kopiSusuProductId' => $kopiSusuProductId,
    'dripBoxProductId' => $dripBoxProductId,
    'tumblerProductId' => $tumblerProductId,
    'airMineralProductId' => $airMineralProductId,
    'coffeeIngredientId' => $coffeeIngredientId,
    'jktDripBoxStock' => (float) DB::table('product_stock_balances')->where('tenant_id', $tenantAId)->where('store_id', $jktStoreId)->where('product_id', $dripBoxProductId)->value('quantity'),
    'jktTumblerStock' => (float) DB::table('product_stock_balances')->where('tenant_id', $tenantAId)->where('store_id', $jktStoreId)->where('product_id', $tumblerProductId)->value('quantity'),
    'jktCoffeeIngredientStock' => (float) DB::table('ingredient_stock_balances')->where('tenant_id', $tenantAId)->where('store_id', $jktStoreId)->where('ingredient_id', $coffeeIngredientId)->value('quantity'),
    'midtransServerKey' => (string) config('services.midtrans.server_key', ''),
    'seededPendingQrisCode' => (string) DB::table('orders')->where('tenant_id', $tenantAId)->where('order_code', 'like', 'DEMO-QRIS-%')->orderBy('id')->value('order_code'),
]);
`);
}

export function getStockSnapshot(tenantId: number, storeId: number, productId: number, ingredientId: number): { productStock: number; ingredientStock: number } {
    return runPhpJson<{ productStock: number; ingredientStock: number }>(`
use Illuminate\\Support\\Facades\\DB;
echo json_encode([
    'productStock' => (float) DB::table('product_stock_balances')->where('tenant_id', ${tenantId})->where('store_id', ${storeId})->where('product_id', ${productId})->value('quantity'),
    'ingredientStock' => (float) DB::table('ingredient_stock_balances')->where('tenant_id', ${tenantId})->where('store_id', ${storeId})->where('ingredient_id', ${ingredientId})->value('quantity'),
]);
`);
}

export function getLatestOrderByCodePrefix(tenantId: number, code: string): OrderSummary {
    return runPhpJson<OrderSummary>(`
use Illuminate\\Support\\Facades\\DB;
$o = DB::table('orders')->where('tenant_id', ${tenantId})->where('order_code', '${code.replace(/'/g, '')}')->first();
if (!$o) { echo json_encode(null); exit; }
echo json_encode([
    'id' => (int) $o->id,
    'order_code' => (string) $o->order_code,
    'order_status' => (string) $o->order_status,
    'order_lifecycle_status' => (string) $o->order_lifecycle_status,
    'payment_status' => (string) $o->payment_status,
    'fulfillment_status' => (string) $o->fulfillment_status,
    'order_amount' => (int) $o->order_amount,
    'discount_amount' => (int) $o->discount_amount,
    'tax_amount' => (int) $o->tax_amount,
    'shift_id' => $o->shift_id ? (int) $o->shift_id : null,
    'offline_client_uuid' => $o->offline_client_uuid ? (string) $o->offline_client_uuid : null,
    'payment_count' => (int) DB::table('payments')->where('tenant_id', ${tenantId})->where('order_id', $o->id)->count(),
    'loyalty_count' => (int) DB::table('loyalty_ledger')->where('tenant_id', ${tenantId})->where('order_id', $o->id)->count(),
    'outbox_count' => (int) DB::table('financial_event_outboxes')->where('tenant_id', ${tenantId})->where('source_id', $o->id)->where('source_type', 'ORDER')->count(),
    'return_count' => (int) DB::table('order_returns')->where('tenant_id', ${tenantId})->where('order_id', $o->id)->count(),
    'refund_count' => (int) DB::table('payment_refunds')->where('tenant_id', ${tenantId})->where('order_id', $o->id)->count(),
    'drawer_refund_count' => (int) DB::table('cash_drawer_movements')->where('tenant_id', ${tenantId})->where('category', 'refund')->where('reason', 'like', '%' . $o->order_code . '%')->count(),
]);
`);
}

export function getLatestCashierOrder(tenantId: number, storeId: number, userId: number, sinceTimestampIso: string): OrderSummary | null {
    return runPhpJson<OrderSummary | null>(`
use Illuminate\\Support\\Facades\\DB;
$o = DB::table('orders')
    ->where('tenant_id', ${tenantId})
    ->where('store_id', ${storeId})
    ->where('user_id', ${userId})
    ->where('created_at', '>=', '${sinceTimestampIso}')
    ->orderByDesc('id')
    ->first();
if (!$o) { echo 'null'; exit; }
echo json_encode([
    'id' => (int) $o->id,
    'order_code' => (string) $o->order_code,
    'order_status' => (string) $o->order_status,
    'order_lifecycle_status' => (string) $o->order_lifecycle_status,
    'payment_status' => (string) $o->payment_status,
    'fulfillment_status' => (string) $o->fulfillment_status,
    'order_amount' => (int) $o->order_amount,
    'discount_amount' => (int) $o->discount_amount,
    'tax_amount' => (int) $o->tax_amount,
    'shift_id' => $o->shift_id ? (int) $o->shift_id : null,
    'offline_client_uuid' => $o->offline_client_uuid ? (string) $o->offline_client_uuid : null,
    'payment_count' => (int) DB::table('payments')->where('tenant_id', ${tenantId})->where('order_id', $o->id)->count(),
    'loyalty_count' => (int) DB::table('loyalty_ledger')->where('tenant_id', ${tenantId})->where('order_id', $o->id)->count(),
    'outbox_count' => (int) DB::table('financial_event_outboxes')->where('tenant_id', ${tenantId})->where('source_id', $o->id)->where('source_type', 'ORDER')->count(),
    'return_count' => (int) DB::table('order_returns')->where('tenant_id', ${tenantId})->where('order_id', $o->id)->count(),
    'refund_count' => (int) DB::table('payment_refunds')->where('tenant_id', ${tenantId})->where('order_id', $o->id)->count(),
    'drawer_refund_count' => (int) DB::table('cash_drawer_movements')->where('tenant_id', ${tenantId})->where('category', 'refund')->where('reason', 'like', '%' . $o->order_code . '%')->count(),
]);
`);
}

export function createPendingQrisOrderWithAttempts(withExpiredRetry: boolean): {
    orderId: number;
    orderCode: string;
    amount: number;
    attempt1ProviderOrderId: string;
    attempt2ProviderOrderId: string | null;
} {
    const expiredFlag = withExpiredRetry ? 'true' : 'false';
    return runPhpJson(`
use App\\Core\\Context\\TenantContext;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Str;

$tenantId = (int) DB::table('tenants')->where('slug', 'kopi-senja')->value('id');
$storeId = (int) DB::table('stores')->where('tenant_id', $tenantId)->where('code', 'STR-JKT-01')->value('id');
$kasirId = (int) DB::table('users')->where('tenant_id', $tenantId)->where('email', 'kasir@pos.test')->value('id');
$custId = (int) DB::table('customers')->where('tenant_id', $tenantId)->orderBy('id')->value('id');
$shiftId = (int) DB::table('shifts')->where('tenant_id', $tenantId)->where('user_id', $kasirId)->where('status', 'open')->value('id');
$deviceId = (int) DB::table('devices')->where('tenant_id', $tenantId)->where('device_code', 'POS-JKT-01')->value('id');
$product = DB::table('products')->where('tenant_id', $tenantId)->where('product_code', 'AIR-MINERAL')->first();
$now = now()->subMinutes(${withExpiredRetry ? 45 : 10});
$orderCode = 'E2E-QRIS-' . strtoupper(Str::random(6));
$amount = (int) $product->product_price;

$orderId = (int) DB::table('orders')->insertGetId([
    'tenant_id' => $tenantId,
    'store_id' => $storeId,
    'user_id' => $kasirId,
    'shift_id' => $shiftId ?: null,
    'created_by_device_id' => $deviceId,
    'customer_id' => $custId ?: null,
    'order_code' => $orderCode,
    'idempotency_key' => (string) Str::uuid(),
    'order_date' => $now->toDateString(),
    'order_type' => 'takeaway',
    'origin_channel' => 'pos_cashier',
    'order_channel' => 'pos_cashier',
    'subtotal_amount' => $amount,
    'dpp_amount' => $amount,
    'discount_amount' => 0,
    'tax_amount' => 0,
    'service_charge_amount' => 0,
    'rounding_adjustment' => 0,
    'order_amount' => $amount,
    'order_status' => 'pending',
    'order_lifecycle_status' => 'submitted',
    'payment_status' => 'awaiting_payment',
    'fulfillment_status' => 'unfulfilled',
    'payment_method' => 'qris',
    'payment_timing' => 'pay_first',
    'created_at' => $now,
    'updated_at' => $now,
]);

DB::table('order_items')->insert([
    'tenant_id' => $tenantId,
    'store_id' => $storeId,
    'order_id' => $orderId,
    'product_id' => $product->id,
    'product_name_snapshot' => $product->product_name,
    'sku_snapshot' => (string) $product->id,
    'qty' => 1,
    'order_price' => $amount,
    'order_subtotal' => $amount,
    'hpp_snapshot' => 0,
    'net_line_total' => $amount,
    'preparation_status' => 'pending',
    'options' => json_encode([]),
    'created_at' => $now,
    'updated_at' => $now,
]);

$att1 = $orderCode . '-ATT1';
DB::table('payment_attempts')->insert([
    'tenant_id' => $tenantId,
    'store_id' => $storeId,
    'order_id' => $orderId,
    'device_id' => $deviceId,
    'shift_id' => $shiftId ?: null,
    'idempotency_key' => 'e2e-att1-' . $orderCode,
    'provider' => 'midtrans',
    'payment_method' => 'qris',
    'provider_order_id' => $att1,
    'amount' => $amount,
    'currency' => 'IDR',
    'status' => ${expiredFlag} ? 'expired' : 'pending_provider',
    'initiated_by_user_id' => $kasirId,
    'created_at' => $now,
    'updated_at' => $now,
]);

$att2 = null;
if (${expiredFlag}) {
    $att2 = $orderCode . '-ATT2';
    DB::table('payment_attempts')->insert([
        'tenant_id' => $tenantId,
        'store_id' => $storeId,
        'order_id' => $orderId,
        'device_id' => $deviceId,
        'shift_id' => $shiftId ?: null,
        'idempotency_key' => 'e2e-att2-' . $orderCode,
        'provider' => 'midtrans',
        'payment_method' => 'qris',
        'provider_order_id' => $att2,
        'amount' => $amount,
        'currency' => 'IDR',
        'status' => 'pending_provider',
        'initiated_by_user_id' => $kasirId,
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);
}

echo json_encode([
    'orderId' => $orderId,
    'orderCode' => $orderCode,
    'amount' => $amount,
    'attempt1ProviderOrderId' => $att1,
    'attempt2ProviderOrderId' => $att2,
]);
`);
}

export function createPaidCashOrderForReturnVoid(qty = 2, productCode = 'RT-TUMBLER'): {
    orderId: number;
    orderCode: string;
    orderAmount: number;
    unitPrice: number;
    productId: number;
    paymentId: number;
    orderItemId: number;
    outboxCountBefore: number;
    stockBefore: number;
} {
    return runPhpJson(`
use App\\Core\\Context\\TenantContext;
use App\\Modules\\Sales\\Services\\TransactionService;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Str;

$tenantId = (int) DB::table('tenants')->where('slug', 'kopi-senja')->value('id');
$storeId = (int) DB::table('stores')->where('tenant_id', $tenantId)->where('code', 'STR-JKT-01')->value('id');
$kasirId = (int) DB::table('users')->where('tenant_id', $tenantId)->where('email', 'kasir@pos.test')->value('id');
$shiftId = (int) DB::table('shifts')->where('tenant_id', $tenantId)->where('user_id', $kasirId)->where('status', 'open')->value('id');
$deviceId = (int) DB::table('devices')->where('tenant_id', $tenantId)->where('device_code', 'POS-JKT-01')->value('id');
$product = DB::table('products')->where('tenant_id', $tenantId)->where('product_code', '${productCode}')->first();

app(TenantContext::class)->setTenantId($tenantId);
app(TenantContext::class)->setStoreId($storeId);

$roundedTotal = (int) (round(($product->product_price * ${qty} * 1.11) / 100) * 100);

$order = app(TransactionService::class)->createTransaction([
    'tenant_id' => $tenantId,
    'store_id' => $storeId,
    'shift_id' => $shiftId,
    'device_id' => $deviceId,
    'order_type' => 'takeaway',
    'payment_method' => 'cash',
    'idempotency_key' => (string) Str::uuid(),
    'items' => [
        ['product_id' => $product->id, 'quantity' => ${qty}],
    ],
    'payments' => [
        [
            'method' => 'cash',
            'amount' => $roundedTotal,
            'amount_tendered' => $roundedTotal,
            'change_amount' => 0,
        ],
    ],
], $kasirId);

$orderItem = $order->orderItems()->first();
$payment = $order->payments()->first();

echo json_encode([
    'orderId' => (int) $order->id,
    'orderCode' => (string) $order->order_code,
    'orderAmount' => (int) $order->order_amount,
    'unitPrice' => (int) $orderItem->order_price,
    'productId' => (int) $product->id,
    'paymentId' => (int) $payment->id,
    'orderItemId' => (int) $orderItem->id,
    'outboxCountBefore' => (int) DB::table('financial_event_outboxes')->where('tenant_id', $tenantId)->count(),
    'stockBefore' => (float) DB::table('product_stock_balances')->where('tenant_id', $tenantId)->where('store_id', $storeId)->where('product_id', $product->id)->value('quantity'),
]);
`);
}

export function runReconcileOrdersWithUnknownMock(): { orderStatuses: Record<string, string> } {
    return runPhpJson(`
use App\\Modules\\Sales\\Models\\Order;
use Illuminate\\Support\\Facades\\Artisan;
use Illuminate\\Support\\Facades\\Config;
use Illuminate\\Support\\Facades\\DB;

Config::set('services.midtrans.mock_status_response', [
    'transaction_status' => 'pending',
    'status_code' => '201',
]);

$code = DB::table('payment_attempts')->where('provider_order_id', 'like', 'E2E-QRIS-%-ATT2')->orderByDesc('id')->value('order_id');
$order = Order::withoutGlobalScopes()->find($code);
// Update created_at > 30m earlier so command picks it up cleanly
Artisan::call('orders:reconcile-midtrans');
$fresh = Order::withoutGlobalScopes()->find($code);
$att2Status = DB::table('payment_attempts')->where('order_id', $code)->where('provider_order_id', 'like', '%-ATT2')->value('status');
echo json_encode([
    'orderStatuses' => [
        'order_status' => (string) $fresh->order_status->value,
        'att2_status' => (string) $att2Status,
    ],
]);
`);
}

export function getOutboxReturnEvents(orderId: number): number {
    return runPhpJson<number>(`
use Illuminate\\Support\\Facades\\DB;
$returnIds = DB::table('order_returns')->where('order_id', ${orderId})->pluck('id')->all();
if ($returnIds === []) {
    echo '0';
    exit;
}
echo json_encode((int) DB::table('financial_event_outboxes')
    ->where('event_type', 'sales.return.refunded')
    ->where('source_type', 'ORDER_RETURN')
    ->whereIn('source_id', $returnIds)
    ->count());
`);
}

export function getMidtransInboxCount(orderCode: string): number {
    return runPhpJson<number>(`
use Illuminate\\Support\\Facades\\DB;
echo json_encode((int) DB::table('payment_inbox_events')
    ->where('provider', 'midtrans')
    ->where('payload', 'like', '%${orderCode.replace(/'/g, '')}%')
    ->count());
`);
}

export function resetCashier2OpenShifts(): void {
    runPhpJson<boolean>(`
use Illuminate\\Support\\Facades\\DB;
$tenantId = (int) DB::table('tenants')->where('slug', 'kopi-senja')->value('id');
$kasir2Id = (int) DB::table('users')->where('tenant_id', $tenantId)->where('email', 'kasir2@pos.test')->value('id');
DB::table('shifts')->where('tenant_id', $tenantId)->where('user_id', $kasir2Id)->where('status', 'open')->update([
    'status' => 'closed',
    'closed_at' => now(),
    'closing_balance' => 0,
    'expected_cash' => 0,
    'cash_difference' => 0,
]);
echo 'true';
`);
}

export function getLatestShiftForUser(userId: number): { id: number; status: string; opening_balance: number; expected_cash: number; closing_balance: number | null; cash_difference: number | null } {
    return runPhpJson(`
use Illuminate\\Support\\Facades\\DB;
$s = DB::table('shifts')->where('user_id', ${userId})->orderByDesc('id')->first();
echo json_encode([
    'id' => (int) $s->id,
    'status' => (string) $s->status,
    'opening_balance' => (int) $s->opening_balance,
    'expected_cash' => (int) ($s->expected_cash ?? 0),
    'closing_balance' => $s->closing_balance !== null ? (int) $s->closing_balance : null,
    'cash_difference' => $s->cash_difference !== null ? (int) $s->cash_difference : null,
]);
`);
}

export function attachUnknownTimeoutAttemptToShift(shiftId: number, resolved = false): number {
    return runPhpJson<number>(`
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Str;
$shift = DB::table('shifts')->where('id', ${shiftId})->first();
$prod = DB::table('products')->where('tenant_id', $shift->tenant_id)->where('product_code', 'AIR-MINERAL')->first();
$code = 'E2E-TMO-' . strtoupper(Str::random(6));
$orderId = DB::table('orders')->insertGetId([
    'tenant_id' => $shift->tenant_id,
    'store_id' => $shift->store_id,
    'user_id' => $shift->user_id,
    'shift_id' => $shift->id,
    'order_code' => $code,
    'idempotency_key' => (string) Str::uuid(),
    'order_date' => now()->toDateString(),
    'order_type' => 'takeaway',
    'origin_channel' => 'pos_cashier',
    'order_channel' => 'pos_cashier',
    'subtotal_amount' => 8000,
    'order_amount' => 8000,
    'order_status' => 'pending',
    'order_lifecycle_status' => 'submitted',
    'payment_status' => 'awaiting_payment',
    'fulfillment_status' => 'unfulfilled',
    'payment_method' => 'qris',
    'created_at' => now(),
    'updated_at' => now(),
]);
$attemptId = (int) DB::table('payment_attempts')->insertGetId([
    'tenant_id' => $shift->tenant_id,
    'store_id' => $shift->store_id,
    'order_id' => $orderId,
    'shift_id' => $shift->id,
    'device_id' => $shift->device_id,
    'idempotency_key' => 'e2e-tmo-att-' . Str::uuid(),
    'provider' => 'midtrans',
    'payment_method' => 'qris',
    'provider_order_id' => $code . '-ATT1',
    'amount' => 8000,
    'currency' => 'IDR',
    'status' => '${resolved ? 'failed' : 'unknown_timeout'}',
    'initiated_by_user_id' => $shift->user_id,
    'created_at' => now(),
    'updated_at' => now(),
]);
echo json_encode($attemptId);
`);
}

export function resolvePaymentAttemptStatus(attemptId: number, status = 'failed'): void {
    runPhpJson<boolean>(`
use Illuminate\\Support\\Facades\\DB;
DB::table('payment_attempts')->where('id', ${attemptId})->update(['status' => '${status}', 'updated_at' => now()]);
echo 'true';
`);
}

export function countOrdersByOfflineUuid(uuid: string): number {
    return runPhpJson<number>(`
use Illuminate\\Support\\Facades\\DB;
echo json_encode((int) DB::table('orders')->where('offline_client_uuid', '${uuid.replace(/'/g, '')}')->count());
`);
}

export function signMidtransSignature(orderId: string, statusCode: string, grossAmount: string): string {
    return runPhpJson<string>(`
$key = (string) config('services.midtrans.server_key', '');
echo json_encode(hash('sha512', '${orderId}${statusCode}${grossAmount}' . $key));
`);
}
