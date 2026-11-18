<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Domain\StateMachines;

use App\Enums\PurchaseOrderStatusEnum;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Platform\Exceptions\IllegalStateTransitionException;

/**
 * Explicit State Machine for Purchase Order Lifecycle Aggregate (ADR-109 / STR-11)
 *
 * Enforces legal state transitions on Purchase Orders:
 * - draft              -> pending_approval, cancelled
 * - pending_approval   -> approved, draft, rejected, cancelled
 * - approved           -> released, partially_received, received, cancelled
 * - released           -> partially_received, received, cancelled
 * - partially_received -> received, closed
 * - received           -> closed
 * - closed / cancelled / rejected -> terminal
 */
final class PurchaseOrderStateMachine
{
    /**
     * @var array<string, list<string>>
     */
    public const LEGAL_TRANSITIONS = [
        'draft' => ['pending_approval', 'cancelled'],
        'pending_approval' => ['approved', 'draft', 'rejected', 'cancelled'],
        'approved' => ['released', 'partially_received', 'received', 'cancelled'],
        'released' => ['partially_received', 'received', 'cancelled'],
        'partially_received' => ['received', 'closed'],
        'received' => ['closed'],
        'closed' => [],
        'cancelled' => [],
        'rejected' => [],
    ];

    public static function canTransition(string|PurchaseOrderStatusEnum $from, string|PurchaseOrderStatusEnum $to): bool
    {
        $fromState = self::normalizeState($from);
        $toState = self::normalizeState($to);

        if ($fromState === $toState) {
            return true;
        }

        $allowed = self::LEGAL_TRANSITIONS[$fromState] ?? [];

        return in_array($toState, $allowed, true);
    }

    public static function assertTransition(string|PurchaseOrderStatusEnum $from, string|PurchaseOrderStatusEnum $to, string $reason = ''): void
    {
        $fromState = self::normalizeState($from);
        $toState = self::normalizeState($to);

        if (! self::canTransition($fromState, $toState)) {
            throw new IllegalStateTransitionException('PurchaseOrder', $fromState, $toState, $reason);
        }
    }

    /**
     * @return list<string>
     */
    public static function allowedTransitionsFrom(string|PurchaseOrderStatusEnum $from): array
    {
        $fromState = self::normalizeState($from);

        return self::LEGAL_TRANSITIONS[$fromState] ?? [];
    }

    public static function isTerminal(string|PurchaseOrderStatusEnum $state): bool
    {
        $normalized = self::normalizeState($state);

        return empty(self::LEGAL_TRANSITIONS[$normalized] ?? []);
    }

    public static function apply(PurchaseOrder $po, string|PurchaseOrderStatusEnum $to, ?string $reason = null): void
    {
        $fromState = self::normalizeState($po->status);
        $toState = self::normalizeState($to);

        self::assertTransition($fromState, $toState, $reason ?? '');

        $po->forceFill([
            'status' => PurchaseOrderStatusEnum::tryFrom($toState) ?? $toState,
        ])->save();
    }

    private static function normalizeState(string|PurchaseOrderStatusEnum|null $state): string
    {
        if ($state === null) {
            return '';
        }

        $raw = $state instanceof PurchaseOrderStatusEnum ? $state->value : (string) $state;

        return strtolower(trim($raw));
    }
}
