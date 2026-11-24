<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Services;

use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderReturn;

class ReturnProportionCalculatorService
{
    /**
     * Menghitung proporsi diskon, pajak, dan biaya layanan atas retur dengan BCMath (FIN-04, REV-03).
     *
     * @return array{discount_reversed: string, tax_returned: string, service_charge_returned: string, net_refund_amount: string}
     */
    public function calculateReturnProportions(Order $order, string $subtotalReturned, string $returnType): array
    {
        $orderSubtotal = (string) ($order->subtotal_amount ?? '0.00');
        $orderDiscount = (string) ($order->discount_amount ?? '0.00');
        $orderTax = (string) ($order->tax_amount ?? '0.00');
        $orderServiceCharge = (string) ($order->service_charge_amount ?? '0.00');

        $priorReturns = OrderReturn::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('status', OrderReturn::STATUS_COMPLETED)
            ->get();

        $priorDiscount = '0.00';
        $priorTax = '0.00';
        $priorServiceCharge = '0.00';
        $priorSubtotal = '0.00';

        foreach ($priorReturns as $pr) {
            $priorDiscount = bcadd($priorDiscount, (string) ($pr->discount_reversed ?? '0.00'), 2);
            $priorTax = bcadd($priorTax, (string) ($pr->tax_returned ?? '0.00'), 2);
            $priorServiceCharge = bcadd($priorServiceCharge, (string) ($pr->service_charge_returned ?? '0.00'), 2);
            $priorSubtotal = bcadd($priorSubtotal, (string) ($pr->subtotal_returned ?? '0.00'), 2);
        }

        $remDiscount = bccomp(bcsub($orderDiscount, $priorDiscount, 2), '0.00', 2) > 0 ? bcsub($orderDiscount, $priorDiscount, 2) : '0.00';
        $remTax = bccomp(bcsub($orderTax, $priorTax, 2), '0.00', 2) > 0 ? bcsub($orderTax, $priorTax, 2) : '0.00';
        $remServiceCharge = bccomp(bcsub($orderServiceCharge, $priorServiceCharge, 2), '0.00', 2) > 0 ? bcsub($orderServiceCharge, $priorServiceCharge, 2) : '0.00';
        $remSubtotal = bccomp(bcsub($orderSubtotal, $priorSubtotal, 2), '0.00', 2) > 0 ? bcsub($orderSubtotal, $priorSubtotal, 2) : '0.00';

        $isFullOrRemainder = ($returnType === OrderReturn::TYPE_FULL) || (bccomp($subtotalReturned, $remSubtotal, 2) >= 0);

        if ($isFullOrRemainder) {
            $discountReversed = $remDiscount;
            $taxReturned = $remTax;
            $serviceChargeReturned = $remServiceCharge;
        } else {
            $ratio = bccomp(bc_num($orderSubtotal), '0.00', 2) > 0
                ? bcdiv($subtotalReturned, $orderSubtotal, 6)
                : '0.000000';

            $calcDiscount = bcmul($orderDiscount, $ratio, 2);
            $calcTax = bcmul($orderTax, $ratio, 2);
            $calcServiceCharge = bcmul($orderServiceCharge, $ratio, 2);

            $discountReversed = bccomp($calcDiscount, $remDiscount, 2) > 0 ? $remDiscount : $calcDiscount;
            $taxReturned = bccomp($calcTax, $remTax, 2) > 0 ? $remTax : $calcTax;
            $serviceChargeReturned = bccomp($calcServiceCharge, $remServiceCharge, 2) > 0 ? $remServiceCharge : $calcServiceCharge;
        }

        $netRefund = bcadd(
            bcsub(bcadd($subtotalReturned, $serviceChargeReturned, 2), $discountReversed, 2),
            $taxReturned,
            2
        );

        return [
            'discount_reversed' => $discountReversed,
            'tax_returned' => $taxReturned,
            'service_charge_returned' => $serviceChargeReturned,
            'net_refund_amount' => $netRefund,
        ];
    }
}
