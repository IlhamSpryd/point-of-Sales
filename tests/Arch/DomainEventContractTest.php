<?php

declare(strict_types=1);

namespace Tests\Arch;

use App\Platform\Events\DomainEventInterface;
use App\Platform\Events\DomainEventRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Architecture Guardrail: Domain Event Contract, Immutability & Anti-Model Leaks (STR-13, ADR-107).
 */
class DomainEventContractTest extends TestCase
{
    public function test_all_registered_domain_events_satisfy_naming_convention(): void
    {
        $events = DomainEventRegistry::all();
        $this->assertNotEmpty($events, 'DomainEventRegistry must contain registered canonical events.');

        $pattern = '/^[a-z0-9_]+\.[a-z0-9_]+\.[a-z0-9_]+\.v\d+$/';

        foreach ($events as $canonicalName => $eventClass) {
            $this->assertTrue(class_exists($eventClass), "Event class '{$eventClass}' does not exist.");
            $this->assertTrue(
                is_subclass_of($eventClass, DomainEventInterface::class),
                "Event class '{$eventClass}' must implement DomainEventInterface."
            );

            $this->assertMatchesRegularExpression(
                $pattern,
                $canonicalName,
                "Domain event '{$canonicalName}' does not adhere to '<module>.<aggregate>.<past_tense>.v<major>' convention."
            );
        }
    }

    public function test_all_registered_domain_events_are_final_and_readonly(): void
    {
        $events = DomainEventRegistry::all();

        foreach ($events as $eventClass) {
            $ref = new ReflectionClass($eventClass);
            $this->assertTrue($ref->isFinal(), "Domain event '{$eventClass}' must be declared 'final'.");
            $this->assertTrue($ref->isReadOnly(), "Domain event '{$eventClass}' must be declared 'readonly'.");
        }
    }

    public function test_all_registered_domain_events_have_zero_eloquent_model_leakage(): void
    {
        $events = DomainEventRegistry::all();

        foreach ($events as $eventClass) {
            $ref = new ReflectionClass($eventClass);
            $file = $ref->getFileName();
            $this->assertNotFalse($file);
            $content = (string) file_get_contents($file);

            $this->assertStringNotContainsString(
                'Illuminate\Database\Eloquent\Model',
                $content,
                "Domain event '{$eventClass}' must NOT import Eloquent Model."
            );

            $this->assertDoesNotMatchRegularExpression(
                '/use\s+App\\\\Modules\\\\[A-Za-z0-9_]+\\\\Models\\\\/',
                $content,
                "Domain event '{$eventClass}' must NOT import Eloquent models from App\Modules\*\Models\*."
            );
        }
    }
}
