<?php

declare(strict_types=1);

namespace App\Modules\Integration\Logistics\Services;

use App\Modules\Integration\Logistics\Adapters\JneCourierAdapter;
use App\Modules\Integration\Logistics\Adapters\JntCourierAdapter;
use App\Modules\Integration\Logistics\Adapters\PosIndonesiaCourierAdapter;
use App\Modules\Integration\Logistics\Adapters\SiCepatCourierAdapter;
use App\Modules\Integration\Logistics\Contracts\CourierAdapterInterface;
use App\Modules\Integration\Logistics\DTOs\ShipmentTrackingDto;
use App\Modules\Integration\Logistics\DTOs\ShippingRateOptionDto;
use App\Modules\Integration\Logistics\DTOs\ShippingRateRequestDto;
use App\Modules\Integration\Logistics\DTOs\WaybillRequestDto;
use App\Modules\Integration\Logistics\DTOs\WaybillResponseDto;
use InvalidArgumentException;

class Logistics3PlAggregatorService
{
    /**
     * @var array<string, CourierAdapterInterface>
     */
    protected array $adapters = [];

    public function __construct(
        ?JneCourierAdapter $jne = null,
        ?SiCepatCourierAdapter $sicepat = null,
        ?PosIndonesiaCourierAdapter $pos = null,
        ?JntCourierAdapter $jnt = null
    ) {
        $this->registerAdapter($jne ?? new JneCourierAdapter());
        $this->registerAdapter($sicepat ?? new SiCepatCourierAdapter());
        $this->registerAdapter($pos ?? new PosIndonesiaCourierAdapter());
        $this->registerAdapter($jnt ?? new JntCourierAdapter());
    }

    public function registerAdapter(CourierAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->getCourierCode()] = $adapter;
    }

    public function getAdapter(string $courierCode): CourierAdapterInterface
    {
        $code = strtolower(trim($courierCode));
        if (! isset($this->adapters[$code])) {
            throw new InvalidArgumentException("Courier adapter for '{$courierCode}' is not supported or registered.");
        }

        return $this->adapters[$code];
    }

    /**
     * @return array<string, string> Map courier_code => courier_name
     */
    public function getAvailableCouriers(): array
    {
        $list = [];
        foreach ($this->adapters as $code => $adapter) {
            $list[$code] = $adapter->getCourierName();
        }

        return $list;
    }

    /**
     * Hitung ongkos kirim ke seluruh kurir 3PL terdaftar.
     *
     * @return array<string, list<ShippingRateOptionDto>>
     */
    public function calculateAllRates(ShippingRateRequestDto $request): array
    {
        $results = [];
        foreach ($this->adapters as $code => $adapter) {
            if ($adapter->isConfigured()) {
                $results[$code] = $adapter->calculateRates($request);
            }
        }

        return $results;
    }

    /**
     * Hitung ongkos kirim dan kembalikan seluruh opsi kurir dalam bentuk flat array terurut tarif termurah.
     *
     * @return list<ShippingRateOptionDto>
     */
    public function getAllRateOptionsFlat(ShippingRateRequestDto $request): array
    {
        $flat = [];
        foreach ($this->calculateAllRates($request) as $options) {
            foreach ($options as $option) {
                $flat[] = $option;
            }
        }

        usort($flat, fn (ShippingRateOptionDto $a, ShippingRateOptionDto $b) => bccomp($a->totalCost ?? $a->cost, $b->totalCost ?? $b->cost, 2));

        return $flat;
    }

    /**
     * Temukan opsi pengiriman paling hemat / termurah.
     */
    public function getCheapestRate(ShippingRateRequestDto $request): ?ShippingRateOptionDto
    {
        $flat = $this->getAllRateOptionsFlat($request);

        return $flat[0] ?? null;
    }

    /**
     * Temukan opsi pengiriman paling cepat (estimasi hari terpendek).
     */
    public function getFastestRate(ShippingRateRequestDto $request): ?ShippingRateOptionDto
    {
        $flat = $this->getAllRateOptionsFlat($request);
        if (empty($flat)) {
            return null;
        }

        usort($flat, function (ShippingRateOptionDto $a, ShippingRateOptionDto $b) {
            $parseDays = function (?string $etd): int {
                if ($etd === null || trim($etd) === '') {
                    return 99;
                }
                if (preg_match('/\b(same\s*day|instan|instant|hari ini)\b/i', $etd)) {
                    return 0;
                }
                if (preg_match('/(\d+)/', $etd, $matches)) {
                    return (int) $matches[1];
                }

                return 99;
            };

            $daysA = $parseDays($a->estimatedDays);
            $daysB = $parseDays($b->estimatedDays);

            return $daysA <=> $daysB;
        });

        return $flat[0];
    }

    /**
     * Terbitkan Airway Bill (AWB / Resi pengiriman) melalui kurir yang dipilih.
     */
    public function createWaybill(WaybillRequestDto $request): WaybillResponseDto
    {
        $adapter = $this->getAdapter($request->courierCode);

        return $adapter->createWaybill($request);
    }

    /**
     * Lacak posisi dan status paket pengiriman secara realtime.
     */
    public function trackShipment(string $courierCode, string $waybillNumber): ShipmentTrackingDto
    {
        $adapter = $this->getAdapter($courierCode);

        return $adapter->trackShipment($waybillNumber);
    }

    /**
     * Batalkan booking pengiriman sebelum paket diserahkan ke kurir.
     */
    public function cancelShipment(string $courierCode, string $waybillNumber, string $reason): bool
    {
        $adapter = $this->getAdapter($courierCode);

        return $adapter->cancelShipment($waybillNumber, $reason);
    }
}
