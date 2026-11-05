<?php

declare(strict_types=1);

namespace App\Support\Color;

use BackedEnum;

/**
 * Single source of truth for status -> colour resolution.
 *
 * Before this class there were four parallel authorities (two badge components,
 * a status-text component and three enum badgeClass() methods) that disagreed:
 * `approved` was blue in one and success in another. This class replaces them.
 *
 * Consumed classes must exist as semantic tokens in resources/css/tokens.css.
 * See docs/ui-ux-audit/color-system/06-erp-status-color-mapping.md.
 */
final class StatusPalette
{
    /**
     * Canonical status string -> variant.
     *
     * Keys are lowercase; input is normalised with trim() + strtolower().
     * Adding a status HERE is the only correct way to introduce a new colour;
     * never add a match() branch inside a Blade component.
     *
     * @var array<string, string>
     */
    private const MAP = [
        // ── success: done and good ──
        'active' => 'success',
        'paid' => 'success',
        'completed' => 'success',
        'complete' => 'success',
        'approved' => 'success',
        'posted' => 'success',
        'matched' => 'success',
        'received' => 'success',
        'fulfilled' => 'success',
        'settled' => 'success',
        'ready' => 'success',
        'in_stock' => 'success',
        'balanced' => 'success',
        'reconciled' => 'success',
        'present' => 'success',
        'fully_received' => 'success',
        'fully_invoiced' => 'success',
        'success' => 'success',

        // ── warning: needs attention, not yet wrong ──
        'pending' => 'warning',
        'pending_approval' => 'warning',
        'draft_approval' => 'warning',
        'partially_paid' => 'warning',
        'partially_received' => 'warning',
        'partially_fulfilled' => 'warning',
        'partially_invoiced' => 'warning',
        'unpaid' => 'warning',
        'in_transit' => 'warning',
        'low_stock' => 'warning',
        'on_hold' => 'warning',
        'overdue' => 'warning',
        'counting' => 'warning',
        'closing' => 'warning',
        'handover_pending' => 'warning',
        'brewing' => 'warning',
        'unmatched' => 'warning',
        'unreconciled' => 'warning',
        'late' => 'warning',
        'expiring_soon' => 'warning',
        'warning' => 'warning',

        // ── destructive: bad or stopped ──
        'cancelled' => 'destructive',
        'canceled' => 'destructive',
        'void' => 'destructive',
        'voided' => 'destructive',
        'rejected' => 'destructive',
        'failed' => 'destructive',
        'disputed' => 'destructive',
        'expired' => 'destructive',
        'blocked' => 'destructive',
        'suspended' => 'destructive',
        'out_of_stock' => 'destructive',
        'variance_flagged' => 'destructive',
        'unbalanced' => 'destructive',
        'absent' => 'destructive',
        'escalated' => 'destructive',
        'danger' => 'destructive',
        'error' => 'destructive',

        // ── info: informational / in-flight ──
        'issued' => 'info',
        'sent' => 'info',
        'confirmed' => 'info',
        'converted' => 'info',
        'dispatched' => 'info',
        'released' => 'info',
        'in_progress' => 'info',
        'open' => 'info',
        'captured' => 'info',
        'probation' => 'info',
        'on_leave' => 'info',
        'overstock' => 'info',
        'over_received' => 'info',
        'over_invoiced' => 'info',
        'info' => 'info',

        // ── neutral: no judgement yet / not applicable ──
        'draft' => 'neutral',
        'inactive' => 'neutral',
        'archived' => 'neutral',
        'closed' => 'neutral',
        'amended' => 'neutral',
        'refunded' => 'neutral',
        'reversed' => 'neutral',
        'not_received' => 'neutral',
        'not_invoiced' => 'neutral',
        'secondary' => 'neutral',
        'neutral' => 'neutral',
        'unknown' => 'neutral',
    ];

    /**
     * Resolve any status representation to a single variant.
     *
     * Accepts an enum (instances or enum-name strings), a raw string, or null.
     * Unrecognised values fall back to Neutral rather than throwing, because a
     * status column must never break a table render.
     */
    public static function variant(string|BackedEnum|null $status, ?StatusVariant $fallback = null): StatusVariant
    {
        $fallback ??= StatusVariant::Neutral;

        if ($status === null) {
            return $fallback;
        }

        $raw = $status instanceof BackedEnum ? (string) $status->value : $status;

        // Enum class names are occasionally passed (e.g. OrderStatus::class).
        if (str_contains($raw, '\\')) {
            $raw = substr($raw, (int) strrpos($raw, '\\') + 1);
        }

        $key = strtolower(trim($raw));
        $key = str_replace([' ', '-'], '_', $key);

        // Callers may pass a canonical variant name directly (e.g. color="error"
        // mapped to "destructive" by x-ui.badge). Resolve those before the map.
        if (in_array($key, ['success', 'warning', 'destructive', 'info', 'neutral'], true)) {
            return StatusVariant::from($key);
        }

        return isset(self::MAP[$key])
            ? StatusVariant::from(self::MAP[$key])
            : $fallback;
    }

    /** Pill/badge classes: muted background + semantic text + hairline border. */
    public static function badgeClasses(StatusVariant|string $variant): string
    {
        return match (self::normalise($variant)) {
            'success' => 'bg-success-muted text-success border-success-border',
            'warning' => 'bg-warning-muted text-warning border-warning-border',
            'destructive' => 'bg-destructive-muted text-destructive border-destructive-border',
            'info' => 'bg-info-muted text-info border-info-border',
            default => 'bg-neutral-muted text-neutral border-neutral-border',
        };
    }

    /** Text-only classes (no background), for inline status cells. */
    public static function textClasses(StatusVariant|string $variant): string
    {
        return match (self::normalise($variant)) {
            'success' => 'text-success',
            'warning' => 'text-warning',
            'destructive' => 'text-destructive',
            'info' => 'text-info',
            default => 'text-neutral',
        };
    }

    /** Solid dot/indicator colour. */
    public static function dotClasses(StatusVariant|string $variant): string
    {
        return match (self::normalise($variant)) {
            'success' => 'bg-success',
            'warning' => 'bg-warning',
            'destructive' => 'bg-destructive',
            'info' => 'bg-info',
            default => 'bg-muted-foreground',
        };
    }

    /**
     * Map legacy variant names used by older call sites onto the five variants.
     * Keeps `variant="danger"` / `"paid"` / `"error"` working without a sweep.
     */
    private static function normalise(StatusVariant|string $variant): string
    {
        if ($variant instanceof StatusVariant) {
            return $variant->value;
        }

        $key = strtolower(trim($variant));

        return match ($key) {
            'success', 'warning', 'destructive', 'info', 'neutral' => $key,
            default => self::variant($key)->value,
        };
    }
}
