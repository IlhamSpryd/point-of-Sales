<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Enums\OrderStatus;
use App\Modules\Sales\Domain\StateMachines\OrderStateMachine;
use App\Platform\Exceptions\IllegalStateTransitionException;
use PHPUnit\Framework\TestCase;

class OrderStateMachineTest extends TestCase
{
    public function test_pending_can_transition_to_valid_next_states(): void
    {
        $this->assertTrue(OrderStateMachine::canTransition('pending', 'paid'));
        $this->assertTrue(OrderStateMachine::canTransition('pending', 'completed'));
        $this->assertTrue(OrderStateMachine::canTransition('pending', 'cancelled'));
        $this->assertTrue(OrderStateMachine::canTransition('pending', 'voided'));
        $this->assertTrue(OrderStateMachine::canTransition(OrderStatus::Pending, OrderStatus::Paid));
    }

    public function test_paid_can_transition_to_valid_next_states(): void
    {
        $this->assertTrue(OrderStateMachine::canTransition('paid', 'completed'));
        $this->assertTrue(OrderStateMachine::canTransition('paid', 'voided'));
        $this->assertTrue(OrderStateMachine::canTransition('paid', 'refunded'));
        $this->assertFalse(OrderStateMachine::canTransition('paid', 'pending'));
    }

    public function test_completed_can_transition_to_voided_and_refunded(): void
    {
        $this->assertTrue(OrderStateMachine::canTransition('completed', 'voided'));
        $this->assertTrue(OrderStateMachine::canTransition('completed', 'refunded'));
        $this->assertFalse(OrderStateMachine::canTransition('completed', 'pending'));
        $this->assertFalse(OrderStateMachine::canTransition('completed', 'paid'));
    }

    public function test_terminal_states_cannot_transition(): void
    {
        $this->assertTrue(OrderStateMachine::isTerminal('cancelled'));
        $this->assertTrue(OrderStateMachine::isTerminal('voided'));
        $this->assertTrue(OrderStateMachine::isTerminal('refunded'));

        $this->assertFalse(OrderStateMachine::canTransition('cancelled', 'paid'));
        $this->assertFalse(OrderStateMachine::canTransition('voided', 'paid'));
    }

    public function test_assert_transition_throws_domain_exception_on_illegal_move(): void
    {
        $this->expectException(IllegalStateTransitionException::class);
        $this->expectExceptionMessage('Illegal state transition for Order: cannot transition from "cancelled" to "completed".');

        OrderStateMachine::assertTransition('cancelled', 'completed');
    }

    public function test_same_state_transition_is_permitted(): void
    {
        $this->assertTrue(OrderStateMachine::canTransition('pending', 'pending'));
        $this->assertTrue(OrderStateMachine::canTransition('completed', 'completed'));
    }
}
