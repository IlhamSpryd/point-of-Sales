<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Contracts\OnlineFoodDeliveryDriverInterface;
use App\Modules\Integration\Drivers\GoFoodDriver;
use App\Modules\Integration\Drivers\GrabFoodDriver;
use InvalidArgumentException;

class OnlineFoodDeliveryManager
{
    /**
     * @var array<string, OnlineFoodDeliveryDriverInterface>
     */
    protected array $drivers = [];

    public function __construct()
    {
        $this->registerDriver(new GrabFoodDriver);
        $this->registerDriver(new GoFoodDriver);
    }

    public function registerDriver(OnlineFoodDeliveryDriverInterface $driver): void
    {
        $this->drivers[strtolower($driver->getProviderName())] = $driver;
    }

    public function driver(string $provider): OnlineFoodDeliveryDriverInterface
    {
        $key = strtolower($provider);
        if (! isset($this->drivers[$key])) {
            throw new InvalidArgumentException("Unsupported Online Food Delivery provider: {$provider}");
        }

        return $this->drivers[$key];
    }

    public function hasDriver(string $provider): bool
    {
        return isset($this->drivers[strtolower($provider)]);
    }
}
