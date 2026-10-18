<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderType;
use App\Models\Order;
use PHPUnit\Framework\TestCase;

class OrderTypeNormalizationTest extends TestCase
{
    public function test_order_type_coerce_handles_takeaway_and_legacy_take_away(): void
    {
        $this->assertSame(OrderType::Takeaway, OrderType::coerce('takeaway'));
        $this->assertSame(OrderType::Takeaway, OrderType::coerce('take_away'));
        $this->assertSame(OrderType::DineIn, OrderType::coerce('dine_in'));
        $this->assertSame(OrderType::Delivery, OrderType::coerce('delivery'));
        $this->assertSame(OrderType::SelfOrder, OrderType::coerce('self_order'));
        $this->assertSame(OrderType::Takeaway, OrderType::coerce(OrderType::Takeaway));
        $this->assertNull(OrderType::coerce('invalid_type'));
        $this->assertNull(OrderType::coerce(null));
        $this->assertNull(OrderType::coerce(123));
    }

    public function test_order_model_casts_legacy_take_away_to_order_type_takeaway(): void
    {
        $order = new Order;
        $order->setRawAttributes(['order_type' => 'take_away']);

        $this->assertInstanceOf(OrderType::class, $order->order_type);
        $this->assertSame(OrderType::Takeaway, $order->order_type);
        $this->assertSame('takeaway', $order->order_type->value);
        $this->assertSame('Takeaway', $order->order_type->label());
        $this->assertSame('secondary', $order->order_type->badgeType());
    }

    public function test_order_model_casts_canonical_values_correctly(): void
    {
        $order = new Order;

        $order->setRawAttributes(['order_type' => 'takeaway']);
        $this->assertSame(OrderType::Takeaway, $order->order_type);

        $order->setRawAttributes(['order_type' => 'dine_in']);
        $this->assertSame(OrderType::DineIn, $order->order_type);

        $order->setRawAttributes(['order_type' => 'delivery']);
        $this->assertSame(OrderType::Delivery, $order->order_type);

        $order->setRawAttributes(['order_type' => 'self_order']);
        $this->assertSame(OrderType::SelfOrder, $order->order_type);

        $order->setRawAttributes(['order_type' => null]);
        $this->assertNull($order->order_type);
    }

    public function test_order_model_sets_and_serializes_takeaway_consistently(): void
    {
        $order = new Order;

        $order->order_type = 'take_away';
        $this->assertSame('takeaway', $order->getAttributes()['order_type']);

        $order->order_type = OrderType::Takeaway;
        $this->assertSame('takeaway', $order->getAttributes()['order_type']);

        $array = $order->toArray();
        $this->assertSame('takeaway', $array['order_type']);
    }
}
