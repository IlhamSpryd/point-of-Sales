<?php

declare(strict_types=1);

namespace App\Platform\Configuration;

use App\Platform\Authorization\Scope;
use InvalidArgumentException;

/**
 * Immutable definition of a typed configuration setting (EXT-02, ADR-112).
 */
final readonly class ConfigDefinition
{
    public const VALID_TYPES = ['bool', 'int', 'string', 'decimal', 'array'];

    public function __construct(
        public string $key,
        public string $type,
        public mixed $default,
        public Scope $scope,
        public string $ownerModule,
        public string $description,
    ) {
        if (! in_array($this->type, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException("Invalid configuration type '{$this->type}' for key '{$this->key}'. Allowed: ".implode(', ', self::VALID_TYPES));
        }

        if (trim($this->description) === '') {
            throw new InvalidArgumentException("Configuration description for key '{$this->key}' must not be empty.");
        }

        $this->castValue($this->default);
    }

    /**
     * Casts and strictly validates a value according to the definition's type.
     */
    public function castValue(mixed $value): mixed
    {
        return match ($this->type) {
            'bool' => is_bool($value)
                ? $value
                : (is_numeric($value) || is_string($value)
                    ? (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw new InvalidArgumentException("Value for key '{$this->key}' cannot be cast to bool: ".var_export($value, true)))
                    : throw new InvalidArgumentException("Value for key '{$this->key}' must be a boolean.")),
            'int' => is_int($value)
                ? $value
                : (is_numeric($value) && (int) $value == $value
                    ? (int) $value
                    : throw new InvalidArgumentException("Value for key '{$this->key}' must be an integer.")),
            'string' => is_string($value)
                ? $value
                : (is_scalar($value)
                    ? (string) $value
                    : throw new InvalidArgumentException("Value for key '{$this->key}' must be a string.")),
            'decimal' => is_numeric($value)
                ? (is_float($value) ? number_format($value, 2, '.', '') : (string) $value)
                : throw new InvalidArgumentException("Value for key '{$this->key}' must be a numeric decimal string."),
            default => is_array($value)
                ? $value
                : throw new InvalidArgumentException("Value for key '{$this->key}' must be an array."),
        };
    }
}
