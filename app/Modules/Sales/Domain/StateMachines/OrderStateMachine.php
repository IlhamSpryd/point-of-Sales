<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\StateMachines;

use App\Enums\OrderStatus;
use App\Modules\Sales\Models\Order;
use App\Platform\Exceptions\IllegalStateTransitionException;

/**
 * Explicit Transactional State Machine for Order Aggregate (ADR-109 / STR-11)
 *
 * Enforces legal state transitions on orders:
 * - pending   -> paid, completed, cancelled, voided (and expired/failed)
 * - paid      -> completed, voided, refunded
 * - completed -> voided, refunded
 * - terminal states cannot transition further.
 */
final class OrderStateMachine
{
    /**
     * @var array<string, list<string>>
     */
    public const LEGAL_TRANSITIONS = [
        'pending' => ['paid', 'completed', 'cancelled', 'voided', 'void', 'expired', 'failed'],
        'paid' => ['completed', 'voided', 'void', 'refunded'],
        'completed' => ['voided', 'void', 'refunded'],
        'cancelled' => [],
        'voided' => [],
        'void' => [],
        'refunded' => [],
        'expired' => [],
        'failed' => [],
    ];

    public static function canTransition(string|OrderStatus $from, string|OrderStatus $to): bool
    {
        $fromState = self::normalizeState($from);
        $toState = self::normalizeState($to);

        if ($fromState === $toState) {
            return true;
        }

        $allowed = self::LEGAL_TRANSITIONS[$fromState] ?? [];

        return in_array($toState, $allowed, true);
    }

    public static function assertTransition(string|OrderStatus $from, string|OrderStatus $to, string $reason = ''): void
    {
        $fromState = self::normalizeState($from);
        $toState = self::normalizeState($to);

        if (! self::canTransition($fromState, $toState)) {
            throw new IllegalStateTransitionException('Order', $fromState, $toState, $reason);
        }
    }

    /**
     * @return list<string>
     */
    public static function allowedTransitionsFrom(string|OrderStatus $from): array
    {
        $fromState = self::normalizeState($from);

        return self::LEGAL_TRANSITIONS[$fromState] ?? [];
    }

    public static function isTerminal(string|OrderStatus $state): bool
    {
        $normalized = self::normalizeState($state);

        return empty(self::LEGAL_TRANSITIONS[$normalized] ?? []);
    }

    public static function apply(Order $order, string|OrderStatus $to, ?string $reason = null): void
    {
        $fromState = self::normalizeState($order->order_status);
        $toState = self::normalizeState($to);

        self::assertTransition($fromState, $toState, $reason ?? '');

        $order->forceFill([
            'order_status' => OrderStatus::tryFrom($toState) ?? $toState,
        ])->save();
    }

    private static function normalizeState(string|OrderStatus|null $state): string
    {
        if ($state === null) {
            return '';
        }

        $raw = $state instanceof OrderStatus ? $state->value : (string) $state;
        $lower = strtolower(trim($raw));

        if ($lower === 'void') {
            return 'voided';
        }

        return $lower;
    }
}
