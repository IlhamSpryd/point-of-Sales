<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Events;

use App\Platform\Events\DomainEventInterface;

/**
 * Versioned domain event emitted when a financial journal entry is posted (STR-13).
 */
final readonly class JournalPostedV1 implements DomainEventInterface
{
    public const EVENT_NAME = 'finance.journal.posted.v1';

    public const SCHEMA_VERSION = 1;

    /**
     * @param  array<int, array{account_id: int, account_code: string, debit: string, credit: string}>  $lines
     */
    public function __construct(
        public string $eventId,
        public int $tenantId,
        public ?int $companyId,
        public ?int $branchId,
        public int $journalEntryId,
        public string $entryNumber,
        public string $sourceType,
        public int $sourceId,
        public string $totalDebit,
        public string $totalCredit,
        public array $lines,
        public string $occurredAt
    ) {}

    public function eventName(): string
    {
        return self::EVENT_NAME;
    }

    public function schemaVersion(): int
    {
        return self::SCHEMA_VERSION;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function occurredAt(): string
    {
        return $this->occurredAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->eventName(),
            'schema_version' => $this->schemaVersion(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'branch_id' => $this->branchId,
            'journal_entry_id' => $this->journalEntryId,
            'entry_number' => $this->entryNumber,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'total_debit' => $this->totalDebit,
            'total_credit' => $this->totalCredit,
            'lines' => $this->lines,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
