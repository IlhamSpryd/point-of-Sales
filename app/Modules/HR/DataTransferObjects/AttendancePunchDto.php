<?php

declare(strict_types=1);

namespace App\Modules\HR\DataTransferObjects;

use App\Modules\HR\Enums\AttendanceSourceEnum;

readonly class AttendancePunchDto
{
    public function __construct(
        public int $tenantId,
        public int $storeId,
        public int $employeeId,
        public AttendanceSourceEnum $source = AttendanceSourceEnum::POS_KIOSK,
        public ?int $deviceId = null,
        public ?string $pin = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $snapshotUrl = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array{
     *     tenant_id: int,
     *     store_id: int,
     *     employee_id: int,
     *     source?: AttendanceSourceEnum|string,
     *     device_id?: int|null,
     *     pin?: string|null,
     *     latitude?: float|null,
     *     longitude?: float|null,
     *     snapshot_url?: string|null,
     *     notes?: string|null
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        $source = $data['source'] ?? AttendanceSourceEnum::POS_KIOSK;
        if (is_string($source)) {
            $source = AttendanceSourceEnum::from($source);
        }

        return new self(
            tenantId: (int) $data['tenant_id'],
            storeId: (int) $data['store_id'],
            employeeId: (int) $data['employee_id'],
            source: $source,
            deviceId: isset($data['device_id']) ? (int) $data['device_id'] : null,
            pin: $data['pin'] ?? null,
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            snapshotUrl: $data['snapshot_url'] ?? null,
            notes: $data['notes'] ?? null,
        );
    }
}
