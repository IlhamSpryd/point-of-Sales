<?php

declare(strict_types=1);

namespace App\Platform\Events;

use App\Modules\Finance\Domain\Events\JournalPostedV1;
use App\Modules\Purchasing\Domain\Events\GoodsReceivedV1;
use App\Modules\Sales\Domain\Events\OrderCompletedV1;
use App\Modules\Sales\Domain\Events\OrderVoidedV1;
use App\Modules\Subscription\Domain\Events\TenantProvisionedV1;
use InvalidArgumentException;

/**
 * Enterprise Domain Event Registry for versioned cross-context integration (STR-13).
 */
class DomainEventRegistry
{
    /**
     * @var array<string, class-string<DomainEventInterface>>
     */
    private static array $events = [];

    private static bool $initialized = false;

    /**
     * Initializes default canonical event registrations.
     */
    public static function boot(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$events = [];
        self::register(OrderCompletedV1::class);
        self::register(OrderVoidedV1::class);
        self::register(GoodsReceivedV1::class);
        self::register(JournalPostedV1::class);
        self::register(TenantProvisionedV1::class);

        self::$initialized = true;
    }

    /**
     * Registers a domain event class in the catalog.
     *
     * @param  class-string<DomainEventInterface>  $eventClass
     */
    public static function register(string $eventClass): void
    {
        if (! is_subclass_of($eventClass, DomainEventInterface::class)) {
            throw new InvalidArgumentException("Class {$eventClass} must implement DomainEventInterface.");
        }

        /** @var string $eventName */
        $eventName = defined("{$eventClass}::EVENT_NAME")
            ? (string) constant("{$eventClass}::EVENT_NAME")
            : (new \ReflectionClass($eventClass))->newInstanceWithoutConstructor()->eventName();

        self::$events[$eventName] = $eventClass;
    }

    /**
     * Returns all registered domain event names and their corresponding classes.
     *
     * @return array<string, class-string<DomainEventInterface>>
     */
    public static function all(): array
    {
        self::boot();

        return self::$events;
    }

    /**
     * Checks if a domain event is registered by its canonical name.
     */
    public static function has(string $eventName): bool
    {
        self::boot();

        return isset(self::$events[$eventName]);
    }

    /**
     * Resolves the event class-string by its canonical event name.
     *
     * @return class-string<DomainEventInterface>|null
     */
    public static function get(string $eventName): ?string
    {
        self::boot();

        return self::$events[$eventName] ?? null;
    }

    /**
     * Returns the total number of registered domain events.
     */
    public static function count(): int
    {
        self::boot();

        return count(self::$events);
    }

    /**
     * Resets the registry state (useful for test isolation).
     */
    public static function reset(): void
    {
        self::$events = [];
        self::$initialized = false;
    }
}
