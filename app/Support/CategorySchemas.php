<?php

namespace App\Support;

use InvalidArgumentException;

class CategorySchemas
{
    /**
     * Field definitions per category slug.
     * type: select|boolean|number
     *
     * @return array<string, array{fields: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            'keyboards' => [
                'fields' => [
                    [
                        'key' => 'layout',
                        'label' => 'Layout',
                        'type' => 'select',
                        'required' => true,
                        'options' => ['60%', '65%', '75%', 'TKL', 'Full'],
                    ],
                    [
                        'key' => 'switch',
                        'label' => 'Switch',
                        'type' => 'select',
                        'required' => true,
                        'options' => ['red', 'brown', 'blue', 'silent', 'other'],
                    ],
                    [
                        'key' => 'connectivity',
                        'label' => 'Connectivity',
                        'type' => 'select',
                        'required' => true,
                        'options' => ['wired', 'wireless', 'tri-mode'],
                    ],
                    [
                        'key' => 'hot_swap',
                        'label' => 'Hot-swap',
                        'type' => 'boolean',
                        'required' => false,
                    ],
                ],
            ],
            'mice' => [
                'fields' => [
                    [
                        'key' => 'connectivity',
                        'label' => 'Connectivity',
                        'type' => 'select',
                        'required' => true,
                        'options' => ['wired', 'wireless'],
                    ],
                    [
                        'key' => 'has_receiver',
                        'label' => 'Receiver included',
                        'type' => 'boolean',
                        'required' => true,
                    ],
                    [
                        'key' => 'shape',
                        'label' => 'Shape',
                        'type' => 'select',
                        'required' => false,
                        'options' => ['ambi', 'ergo', 'other'],
                    ],
                    [
                        'key' => 'weight_g',
                        'label' => 'Weight (g)',
                        'type' => 'number',
                        'required' => false,
                        'min' => 20,
                        'max' => 200,
                    ],
                ],
            ],
            'monitors' => [
                'fields' => [
                    [
                        'key' => 'size_inch',
                        'label' => 'Size (inch)',
                        'type' => 'select',
                        'required' => true,
                        'options' => ['24', '27', '32', 'other'],
                    ],
                    [
                        'key' => 'refresh_hz',
                        'label' => 'Refresh rate (Hz)',
                        'type' => 'select',
                        'required' => true,
                        'options' => ['60', '144', '165', '240'],
                    ],
                    [
                        'key' => 'panel',
                        'label' => 'Panel',
                        'type' => 'select',
                        'required' => true,
                        'options' => ['ips', 'va', 'oled', 'other'],
                    ],
                    [
                        'key' => 'resolution',
                        'label' => 'Resolution',
                        'type' => 'select',
                        'required' => true,
                        'options' => ['fhd', 'qhd', '4k'],
                    ],
                ],
            ],
        ];
    }

    public static function forSlug(?string $slug): ?array
    {
        if (! $slug) {
            return null;
        }

        return self::all()[strtolower($slug)] ?? null;
    }

    public static function assertBrand(mixed $brand): string
    {
        $value = trim((string) $brand);
        if ($value === '' || mb_strlen($value) < 1) {
            throw new InvalidArgumentException('Brand is required.');
        }
        if (mb_strlen($value) > 80) {
            throw new InvalidArgumentException('Brand must be at most 80 characters.');
        }

        return $value;
    }

    /**
     * Validate and normalize attributes for a category slug.
     *
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>
     */
    public static function assertAttributes(?string $slug, mixed $input): array
    {
        $schema = self::forSlug($slug);
        if (! $schema) {
            throw new InvalidArgumentException('Unsupported category for product attributes.');
        }

        if (! is_array($input)) {
            $input = [];
        }

        $allowedKeys = collect($schema['fields'])->pluck('key')->all();
        $unknown = array_diff(array_keys($input), $allowedKeys);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown attributes: '.implode(', ', $unknown).'.');
        }

        $normalized = [];

        foreach ($schema['fields'] as $field) {
            $key = $field['key'];
            $has = array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '';
            $required = (bool) ($field['required'] ?? false);

            if (! $has) {
                if ($required) {
                    throw new InvalidArgumentException(($field['label'] ?? $key).' is required.');
                }
                continue;
            }

            $normalized[$key] = self::castField($field, $input[$key]);
        }

        return $normalized;
    }

    /**
     * Human-readable rows for API/UI.
     *
     * @param  array<string, mixed>|null  $attributes
     * @return list<array{label: string, value: string, key: string}>
     */
    public static function labeledAttributes(?string $slug, ?array $attributes): array
    {
        $schema = self::forSlug($slug);
        if (! $schema || ! is_array($attributes) || $attributes === []) {
            return [];
        }

        $rows = [];
        foreach ($schema['fields'] as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $attributes)) {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'label' => $field['label'] ?? $key,
                'value' => self::formatDisplayValue($field, $attributes[$key]),
            ];
        }

        return $rows;
    }

    private static function castField(array $field, mixed $value): mixed
    {
        $type = $field['type'] ?? 'select';

        return match ($type) {
            'boolean' => self::castBoolean($field, $value),
            'number' => self::castNumber($field, $value),
            default => self::castSelect($field, $value),
        };
    }

    private static function castSelect(array $field, mixed $value): string
    {
        $string = is_string($value) || is_numeric($value) ? (string) $value : '';
        $options = $field['options'] ?? [];
        if (! in_array($string, $options, true)) {
            throw new InvalidArgumentException(
                'Invalid value for '.($field['label'] ?? $field['key']).'.'
            );
        }

        return $string;
    }

    private static function castBoolean(array $field, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true' || $value === 'yes') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false' || $value === 'no') {
            return false;
        }

        throw new InvalidArgumentException(
            'Invalid value for '.($field['label'] ?? $field['key']).'.'
        );
    }

    private static function castNumber(array $field, mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException(
                'Invalid value for '.($field['label'] ?? $field['key']).'.'
            );
        }
        $number = (int) round((float) $value);
        $min = $field['min'] ?? null;
        $max = $field['max'] ?? null;
        if ($min !== null && $number < $min) {
            throw new InvalidArgumentException(($field['label'] ?? $field['key'])." must be at least {$min}.");
        }
        if ($max !== null && $number > $max) {
            throw new InvalidArgumentException(($field['label'] ?? $field['key'])." must be at most {$max}.");
        }

        return $number;
    }

    private static function formatDisplayValue(array $field, mixed $value): string
    {
        $type = $field['type'] ?? 'select';
        if ($type === 'boolean') {
            return $value ? 'Yes' : 'No';
        }
        if ($type === 'number') {
            return (string) $value;
        }

        return match ((string) $value) {
            'fhd' => 'Full HD',
            'qhd' => 'QHD',
            '4k' => '4K',
            'ips' => 'IPS',
            'va' => 'VA',
            'oled' => 'OLED',
            'ambi' => 'Ambidextrous',
            'ergo' => 'Ergonomic',
            'tri-mode' => 'Tri-mode',
            default => ucfirst((string) $value),
        };
    }
}
