<?php

namespace App\Support;

use InvalidArgumentException;

class CategorySchemas
{
    /**
     * Field definitions per category slug.
     * type: select|boolean|number|text
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
                        'allow_other' => true,
                        'options' => ['red', 'brown', 'blue', 'silent', 'other'],
                    ],
                    [
                        'key' => 'connectivity',
                        'label' => 'Connectivity',
                        'type' => 'text',
                        'required' => true,
                        'max' => 80,
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
                        'allow_other' => true,
                        'options' => ['wired', 'wireless', 'bluetooth', '2.4ghz', 'tri-mode', 'other'],
                    ],
                    [
                        'key' => 'shape',
                        'label' => 'Shape',
                        'type' => 'select',
                        'required' => true,
                        'allow_other' => true,
                        'options' => ['ambi', 'ergo', 'other'],
                    ],
                    [
                        'key' => 'weight_g',
                        'label' => 'Weight (g)',
                        'type' => 'number',
                        'required' => true,
                        'min' => 20,
                        'max' => 250,
                    ],
                    [
                        'key' => 'max_dpi',
                        'label' => 'Max DPI',
                        'type' => 'number',
                        'required' => true,
                        'min' => 400,
                        'max' => 50000,
                    ],
                    [
                        'key' => 'polling_hz',
                        'label' => 'Polling Rate (Hz)',
                        'type' => 'select',
                        'required' => true,
                        'allow_other' => true,
                        'options' => ['125', '250', '500', '1000', '2000', '4000', '8000', 'other'],
                    ],
                    [
                        'key' => 'buttons',
                        'label' => 'Number of Buttons',
                        'type' => 'number',
                        'required' => true,
                        'min' => 2,
                        'max' => 20,
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
                        'allow_other' => true,
                        'options' => [
                            '15.6', '16', '17.3', '18.5', '19', '20',
                            '21.5', '22', '23', '23.8', '24', '24.5', '25',
                            '27', '28', '29', '31.5', '32', '34',
                            '38', '40', '42', '43', '45', '49', '55', '57',
                            'other',
                        ],
                    ],
                    [
                        'key' => 'refresh_hz',
                        'label' => 'Refresh rate (Hz)',
                        'type' => 'select',
                        'required' => true,
                        'allow_other' => true,
                        'options' => [
                            '60', '75', '90', '100', '120', '144', '165',
                            '170', '180', '200', '240', '250', '260', '280',
                            '300', '360', '400', '480', '500', '540', '600',
                            'other',
                        ],
                    ],
                    [
                        'key' => 'panel',
                        'label' => 'Panel / technology',
                        'type' => 'select',
                        'required' => true,
                        'allow_other' => true,
                        'options' => [
                            'ips', 'fast_ips', 'nano_ips', 'ips_black',
                            'va', 'fast_va',
                            'tn',
                            'oled', 'woled', 'qd_oled',
                            'other',
                        ],
                    ],
                    [
                        'key' => 'resolution',
                        'label' => 'Resolution',
                        'type' => 'select',
                        'required' => true,
                        'allow_other' => true,
                        'options' => [
                            'hd_720', 'hd_1366', 'hd_plus',
                            'fhd', 'wuxga', 'uw_fhd',
                            'qhd', 'wqxga', 'uwqhd',
                            'dfhd', 'uwqhd_plus', '4k',
                            'dqhd', '5k2k', '5k', '6k',
                            'duhd', '8k',
                            'other',
                        ],
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
            'text' => self::castText($field, $value),
            default => self::castSelect($field, $value),
        };
    }

    private static function castSelect(array $field, mixed $value): string
    {
        $string = trim(is_string($value) || is_numeric($value) ? (string) $value : '');
        $options = $field['options'] ?? [];
        $allowOther = (bool) ($field['allow_other'] ?? false);

        if (in_array($string, $options, true)) {
            if ($allowOther && $string === 'other') {
                throw new InvalidArgumentException(
                    'Enter the '.strtolower((string) ($field['label'] ?? $field['key'])).' when Other is selected.'
                );
            }

            return $string;
        }

        if ($allowOther) {
            return self::castText($field, $string);
        }

        throw new InvalidArgumentException(
            'Invalid value for '.($field['label'] ?? $field['key']).'.'
        );
    }

    private static function castText(array $field, mixed $value): string
    {
        $string = trim(is_string($value) || is_numeric($value) ? (string) $value : '');
        $label = $field['label'] ?? $field['key'];
        if ($string === '') {
            throw new InvalidArgumentException($label.' is required.');
        }
        $max = (int) ($field['max'] ?? 80);
        if (mb_strlen($string) > $max) {
            throw new InvalidArgumentException($label." must be at most {$max} characters.");
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
        $key = (string) ($field['key'] ?? '');
        $string = (string) $value;

        if ($type === 'number' || $type === 'text') {
            if ($key === 'weight_g') {
                return $string.' g';
            }
            if ($key === 'max_dpi') {
                return $string.' DPI';
            }

            return $string;
        }

        if ($key === 'size_inch' && $string !== 'other') {
            return $string.'"';
        }
        if (in_array($key, ['refresh_hz', 'polling_hz'], true) && $string !== 'other') {
            return $string.' Hz';
        }

        return match ($string) {
            'fhd' => '1920 x 1080 — Full HD / FHD',
            'qhd' => '2560 x 1440 — QHD / WQHD / 1440p',
            '4k' => '3840 x 2160 — 4K UHD',
            'hd_720' => '1280 x 720 — HD / 720p',
            'hd_1366' => '1366 x 768 — HD',
            'hd_plus' => '1600 x 900 — HD+',
            'wuxga' => '1920 x 1200 — WUXGA',
            'uw_fhd' => '2560 x 1080 — UW-FHD',
            'wqxga' => '2560 x 1600 — WQXGA',
            'uwqhd' => '3440 x 1440 — UWQHD',
            'dfhd' => '3840 x 1080 — DFHD',
            'uwqhd_plus' => '3840 x 1600 — UWQHD+',
            'dqhd' => '5120 x 1440 — DQHD',
            '5k2k' => '5120 x 2160 — 5K2K / WUHD',
            '5k' => '5120 x 2880 — 5K',
            '6k' => '6016 x 3384 — 6K',
            'duhd' => '7680 x 2160 — DUHD',
            '8k' => '7680 x 4320 — 8K UHD',
            'ips' => 'IPS',
            'fast_ips' => 'Fast IPS',
            'nano_ips' => 'Nano IPS',
            'ips_black' => 'IPS Black',
            'va' => 'VA',
            'fast_va' => 'Fast VA / Rapid VA',
            'tn' => 'TN',
            'oled' => 'OLED',
            'woled' => 'WOLED',
            'qd_oled' => 'QD-OLED',
            'ambi' => 'Ambidextrous',
            'ergo' => 'Ergonomic',
            'tri-mode' => 'Tri-mode',
            'wired' => 'Wired',
            'wireless' => 'Wireless',
            'bluetooth' => 'Bluetooth',
            '2.4ghz' => '2.4GHz wireless',
            default => ucfirst((string) $value),
        };
    }
}
