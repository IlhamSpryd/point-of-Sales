<?php

declare(strict_types=1);

namespace Tests\Unit\Purchasing;

use App\Enums\PurchaseOrderStatusEnum;
use App\Modules\Purchasing\Domain\StateMachines\PurchaseOrderStateMachine;
use App\Platform\Exceptions\IllegalStateTransitionException;
use PHPUnit\Framework\TestCase;

class PurchaseOrderStateMachineTest extends TestCase
{
    public function test_draft_can_transition_to_pending_approval_and_cancelled(): void
    {
        $this->assertTrue(PurchaseOrderStateMachine::canTransition('draft', 'pending_approval'));
        $this->assertTrue(PurchaseOrderStateMachine::canTransition('draft', 'cancelled'));
        $this->assertTrue(PurchaseOrderStateMachine::canTransition(PurchaseOrderStatusEnum::Draft, PurchaseOrderStatusEnum::PendingApproval));
        $this->assertFalse(PurchaseOrderStateMachine::canTransition('draft', 'received'));
    }

    public function test_pending_approval_transitions(): void
    {
        $this->assertTrue(PurchaseOrderStateMachine::canTransition('pending_approval', 'approved'));
        $this->assertTrue(PurchaseOrderStateMachine::canTransition('pending_approval', 'rejected'));
        $this->assertTrue(PurchaseOrderStateMachine::canTransition('pending_approval', 'draft'));
        $this->assertTrue(PurchaseOrderStateMachine::canTransition('pending_approval', 'cancelled'));
        $this->assertFalse(PurchaseOrderStateMachine::canTransition('pending_approval', 'received'));
    }

    public function test_approved_can_transition_to_downstream_fulfillment(): void
    {
        $this->assertTrue(PurchaseOrderStateMachine::canTransition('approved', 'released'));
        $this->assertTrue(PurchaseOrderStateMachine::canTransition('approved', 'partially_received'));
        $this->assertTrue(PurchaseOrderStateMachine::canTransition('approved', 'received'));
        $this->assertTrue(PurchaseOrderStateMachine::canTransition('approved', 'cancelled'));
        $this->assertFalse(PurchaseOrderStateMachine::canTransition('approved', 'draft'));
    }

    public function test_receiving_transitions_to_closed(): void
    {
        $this->assertTrue(PurchaseOrderStateMachine::canTransition('partially_received', 'received'));
        $this->assertTrue(PurchaseOrderStateMachine::canTransition('partially_received', 'closed'));
        $this->assertTrue(PurchaseOrderStateMachine::canTransition('received', 'closed'));
        $this->assertFalse(PurchaseOrderStateMachine::canTransition('received', 'draft'));
    }

    public function test_terminal_states_cannot_transition(): void
    {
        $this->assertTrue(PurchaseOrderStateMachine::isTerminal('closed'));
        $this->assertTrue(PurchaseOrderStateMachine::isTerminal('cancelled'));
        $this->assertTrue(PurchaseOrderStateMachine::isTerminal('rejected'));

        $this->assertFalse(PurchaseOrderStateMachine::canTransition('closed', 'draft'));
        $this->assertFalse(PurchaseOrderStateMachine::canTransition('cancelled', 'approved'));
        $this->assertFalse(PurchaseOrderStateMachine::canTransition('rejected', 'approved'));
    }

    public function test_assert_transition_throws_domain_exception_on_illegal_move(): void
    {
        $this->expectException(IllegalStateTransitionException::class);
        $this->expectExceptionMessage('Illegal state transition for PurchaseOrder: cannot transition from "closed" to "approved".');

        PurchaseOrderStateMachine::assertTransition('closed', 'approved');
    }

    public function test_same_state_transition_is_permitted(): void
    {
        $this->assertTrue(PurchaseOrderStateMachine::canTransition('draft', 'draft'));
        $this->assertTrue(PurchaseOrderStateMachine::canTransition('approved', 'approved'));
    }
}
