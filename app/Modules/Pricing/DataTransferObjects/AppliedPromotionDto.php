<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DataTransferObjects;

final readonly class AppliedPromotionDto
{
    public function __construct(
        public int $campaignId,
        public int $ruleId,
        public int $rewardId,
        public string $type,
        public float $discountAmount,
        public ?int $targetLineIndex = null,
        public string $description = '',
        public array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'campaign_id' => $this->campaignId,
            'rule_id' => $this->ruleId,
            'reward_id' => $this->rewardId,
            'type' => $this->type,
            'discount_amount' => $this->discountAmount,
            'target_line_index' => $this->targetLineIndex,
            'description' => $this->description,
            'meta' => $this->meta,
        ];
    }
}
