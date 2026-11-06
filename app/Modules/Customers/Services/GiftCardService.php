<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Models\User;
use App\Modules\Customers\Enums\GiftCardLedgerTypeEnum;
use App\Modules\Customers\Enums\GiftCardStatusEnum;
use App\Modules\Customers\Models\GiftCard;
use App\Modules\Customers\Models\GiftCardLedger;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GiftCardService
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Terbitkan Gift Card baru beserta saldo awal pada buku besar.
     *
     * @param  array<string, mixed>  $data
     */
    public function issueGiftCard(array $data, User $actor, ?string $idempotencyKey = null): GiftCard
    {
        $tenantId = (int) $this->tenantContext->requireTenantId();
        $storeId = (int) ($data['store_id'] ?? $this->tenantContext->getStoreId() ?? 0);

        $initialAmount = bcadd(bc_num($data['amount'] ?? $data['initial_amount'] ?? '0'), '0', 2);
        if (bccomp($initialAmount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => ['Gift Card initial balance must be greater than 0.']]);
        }

        $cardNumber = ! empty($data['card_number'])
            ? strtoupper(trim((string) $data['card_number']))
            : 'GC-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4));

        $existing = GiftCard::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('card_number', $cardNumber)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages(['card_number' => ["Card number '{$cardNumber}' is already registered for this tenant."]]);
        }

        $pinHash = ! empty($data['pin']) ? Hash::make((string) $data['pin']) : null;
        $expiresAt = ! empty($data['expires_at']) ? Carbon::parse($data['expires_at']) : null;
        $customerId = ! empty($data['customer_id']) ? (int) $data['customer_id'] : null;

        return DB::transaction(function () use (
            $tenantId,
            $storeId,
            $cardNumber,
            $pinHash,
            $customerId,
            $initialAmount,
            $expiresAt,
            $actor,
            $idempotencyKey
        ) {
            $giftCard = GiftCard::create([
                'tenant_id' => $tenantId,
                'issued_store_id' => $storeId > 0 ? $storeId : null,
                'card_number' => $cardNumber,
                'pin_hash' => $pinHash,
                'customer_id' => $customerId,
                'initial_balance' => $initialAmount,
                'current_balance' => $initialAmount,
                'status' => GiftCardStatusEnum::ACTIVE,
                'expires_at' => $expiresAt,
            ]);

            GiftCardLedger::create([
                'tenant_id' => $tenantId,
                'gift_card_id' => $giftCard->id,
                'store_id' => $storeId > 0 ? $storeId : 1,
                'entry_type' => GiftCardLedgerTypeEnum::ISSUE,
                'amount' => $initialAmount,
                'balance_after' => $initialAmount,
                'reference_type' => 'GIFT_CARD_ISSUE',
                'reference_id' => $giftCard->id,
                'idempotency_key' => $idempotencyKey ?: 'gc_issue:'.$giftCard->id.':'.(string) Str::uuid(),
                'actor_user_id' => $actor->id,
                'created_at' => Carbon::now(),
            ]);

            return $giftCard;
        });
    }

    /**
     * Tukarkan saldo Gift Card untuk pembayaran dengan lock baris deterministik A0/A8.
     *
     * @return array{gift_card_id: int, card_number: string, amount_redeemed: string, balance_after: string}
     */
    public function redeemGiftCard(
        string $cardNumber,
        string $amount,
        int $storeId,
        ?string $pin = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?User $actor = null,
        ?string $idempotencyKey = null
    ): array {
        $tenantId = (int) $this->tenantContext->requireTenantId();
        $cardNumber = strtoupper(trim($cardNumber));
        $redeemAmount = bcadd(bc_num($amount), '0', 2);

        if (bccomp($redeemAmount, '0.00', 2) <= 0) {
            throw new DomainException('Gift Card redemption amount must be greater than 0.');
        }

        return DB::transaction(function () use (
            $tenantId,
            $cardNumber,
            $redeemAmount,
            $storeId,
            $pin,
            $referenceType,
            $referenceId,
            $actor,
            $idempotencyKey
        ) {
            /** @var GiftCard $lockedCard */
            $lockedCard = GiftCard::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('card_number', $cardNumber)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedCard->isValidForRedemption()) {
                throw new DomainException("Gift Card '{$cardNumber}' is invalid, expired, or has zero balance.");
            }

            if ($lockedCard->pin_hash !== null) {
                if (empty($pin) || ! Hash::check($pin, $lockedCard->pin_hash)) {
                    throw new DomainException('Incorrect Gift Card PIN entered.');
                }
            }

            if (bccomp((string) $lockedCard->current_balance, $redeemAmount, 2) < 0) {
                throw new DomainException("Insufficient Gift Card balance. Current balance: {$lockedCard->current_balance}, required: {$redeemAmount}.");
            }

            $newBalance = bcsub((string) $lockedCard->current_balance, $redeemAmount, 2);
            $lockedCard->current_balance = $newBalance;
            if (bccomp($newBalance, '0.00', 2) === 0) {
                $lockedCard->status = GiftCardStatusEnum::DEPLETED;
            }
            $lockedCard->save();

            GiftCardLedger::create([
                'tenant_id' => $tenantId,
                'gift_card_id' => $lockedCard->id,
                'store_id' => $storeId,
                'entry_type' => GiftCardLedgerTypeEnum::REDEEM,
                'amount' => '-'.$redeemAmount,
                'balance_after' => $newBalance,
                'reference_type' => $referenceType ?: 'ORDER_PAYMENT',
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey ?: 'gc_redeem:'.$lockedCard->id.':'.(string) Str::uuid(),
                'actor_user_id' => $actor?->id,
                'created_at' => Carbon::now(),
            ]);

            return [
                'gift_card_id' => (int) $lockedCard->id,
                'card_number' => $lockedCard->card_number,
                'amount_redeemed' => $redeemAmount,
                'balance_after' => $newBalance,
            ];
        });
    }

    /**
     * Kembalikan dana / reversal retur ke Gift Card.
     *
     * @return array<string, mixed>
     */
    public function refundToGiftCard(
        string $cardNumber,
        string $amount,
        int $storeId,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?User $actor = null,
        ?string $idempotencyKey = null
    ): array {
        $tenantId = (int) $this->tenantContext->requireTenantId();
        $cardNumber = strtoupper(trim($cardNumber));
        $refundAmount = bcadd(bc_num($amount), '0', 2);

        if (bccomp($refundAmount, '0.00', 2) <= 0) {
            throw new DomainException('Gift Card refund amount must be greater than 0.');
        }

        return DB::transaction(function () use (
            $tenantId,
            $cardNumber,
            $refundAmount,
            $storeId,
            $referenceType,
            $referenceId,
            $actor,
            $idempotencyKey
        ) {
            /** @var GiftCard $lockedCard */
            $lockedCard = GiftCard::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('card_number', $cardNumber)
                ->lockForUpdate()
                ->firstOrFail();

            $newBalance = bcadd((string) $lockedCard->current_balance, $refundAmount, 2);

            // Invariant: current_balance <= initial_balance
            if (bccomp($newBalance, (string) $lockedCard->initial_balance, 2) > 0) {
                throw new DomainException('Final Gift Card balance after refund cannot exceed the original card balance.');
            }

            $lockedCard->current_balance = $newBalance;
            if ($lockedCard->status === GiftCardStatusEnum::DEPLETED) {
                $lockedCard->status = GiftCardStatusEnum::ACTIVE;
            }
            $lockedCard->save();

            GiftCardLedger::create([
                'tenant_id' => $tenantId,
                'gift_card_id' => $lockedCard->id,
                'store_id' => $storeId,
                'entry_type' => GiftCardLedgerTypeEnum::REFUND_REVERSAL,
                'amount' => $refundAmount,
                'balance_after' => $newBalance,
                'reference_type' => $referenceType ?: 'ORDER_RETURN',
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey ?: 'gc_refund:'.$lockedCard->id.':'.(string) Str::uuid(),
                'actor_user_id' => $actor?->id,
                'created_at' => Carbon::now(),
            ]);

            return [
                'gift_card_id' => (int) $lockedCard->id,
                'card_number' => $lockedCard->card_number,
                'amount_refunded' => $refundAmount,
                'balance_after' => $newBalance,
            ];
        });
    }

    /**
     * Bekukan / blokir Gift Card.
     */
    public function freezeGiftCard(int $cardId, User $actor): GiftCard
    {
        $tenantId = (int) $this->tenantContext->requireTenantId();

        /** @var GiftCard $card */
        $card = GiftCard::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($cardId);

        $card->status = GiftCardStatusEnum::BLOCKED;
        $card->save();

        return $card;
    }

    /**
     * Buka kembali pembekuan / unblock Gift Card.
     */
    public function unfreezeGiftCard(int $cardId, User $actor): GiftCard
    {
        $tenantId = (int) $this->tenantContext->requireTenantId();

        /** @var GiftCard $card */
        $card = GiftCard::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($cardId);

        if ($card->expires_at && Carbon::now()->gt($card->expires_at)) {
            $card->status = GiftCardStatusEnum::EXPIRED;
        } elseif (bccomp((string) $card->current_balance, '0.00', 2) <= 0) {
            $card->status = GiftCardStatusEnum::DEPLETED;
        } else {
            $card->status = GiftCardStatusEnum::ACTIVE;
        }

        $card->save();

        return $card;
    }
}
