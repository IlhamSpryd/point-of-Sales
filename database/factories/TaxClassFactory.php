<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TaxClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxClass>
 */
class TaxClassFactory extends Factory
{
    protected $model = TaxClass::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true).' Tax',
            'code' => strtoupper($this->faker->unique()->bothify('TAX-##')),
            'rate' => 11.0000,
            'is_active' => true,
        ];
    }

    public function zeroRate(): static
    {
        return $this->state(fn () => [
            'rate' => 0.0000,
        ]);
    }
}
