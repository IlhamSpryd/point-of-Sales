<?php

declare(strict_types=1);

namespace App\Casts;

use App\Enums\OrderType;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class OrderTypeCast implements CastsAttributes
{
    /**
     * Cast the given value from storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?OrderType
    {
        if ($value === null) {
            return null;
        }

        return OrderType::coerce($value);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $coerced = OrderType::coerce($value);
        if ($coerced !== null) {
            return $coerced->value;
        }

        return (string) $value;
    }

    /**
     * Get the serialized representation of the value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof OrderType ? $value->value : (string) $value;
    }
}
