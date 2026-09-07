<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Models\Setting;
use InvalidArgumentException;
use JsonException;

class Settings
{
    /** @var array<string, array{value: string|null, type: string}>|null */
    private ?array $memoized = null;

    /**
     * @throws JsonException
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        if (! array_key_exists($key, $settings)) {
            return $default;
        }

        $value = $settings[$key]['value'];

        if ($value === null) {
            return null;
        }

        return match ($settings[$key]['type']) {
            'int' => (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            'json' => json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            default => $value,
        };
    }

    /**
     * @throws JsonException
     */
    public function set(string $key, mixed $value, ?string $type = null): void
    {
        $resolvedType = $type ?? $this->inferType($value);

        if (! in_array($resolvedType, ['string', 'int', 'bool', 'json'], true)) {
            throw new InvalidArgumentException("Unsupported setting type: {$resolvedType}");
        }

        Setting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $this->serialize($value, $resolvedType),
                'type' => $resolvedType,
            ],
        );

        $this->memoized = null;
    }

    /** @return array<string, array{value: string|null, type: string}> */
    private function all(): array
    {
        if ($this->memoized !== null) {
            return $this->memoized;
        }

        $this->memoized = [];

        foreach (Setting::query()->get(['key', 'value', 'type']) as $setting) {
            $this->memoized[$setting->key] = [
                'value' => $setting->value,
                'type' => $setting->type,
            ];
        }

        return $this->memoized;
    }

    private function inferType(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'int',
            is_bool($value) => 'bool',
            is_array($value), is_object($value) => 'json',
            default => 'string',
        };
    }

    /**
     * @throws JsonException
     */
    private function serialize(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => (string) ((int) $value),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0',
            'json' => json_encode($value, JSON_THROW_ON_ERROR),
            default => (string) $value,
        };
    }
}
