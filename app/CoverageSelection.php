<?php
declare(strict_types=1);

namespace AcePortal;

final class CoverageSelection
{
    public const LABELS = [
        'commercial_general_liability' => 'Commercial General Liability',
        'automobile_liability' => 'Automobile Liability',
        'physical_damage' => 'Physical Damage',
        'motor_truck_cargo' => 'Motor Truck Cargo',
    ];

    public static function normalize(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) return [];

        $selected = [];
        foreach ($raw as $key) {
            if (is_string($key) && isset(self::LABELS[$key])) {
                $selected[$key] = true;
            }
        }

        return array_values(array_filter(
            array_keys(self::LABELS),
            static fn(string $key): bool => isset($selected[$key])
        ));
    }

    public static function validate(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Invalid coverage selection.');
        }
        if (count($raw) > count(self::LABELS)) {
            throw new \InvalidArgumentException('Select only the four available coverages.');
        }

        foreach ($raw as $key) {
            if (!is_string($key) || !isset(self::LABELS[$key])) {
                throw new \InvalidArgumentException('One of the selected coverages is invalid.');
            }
        }

        return self::normalize($raw);
    }

    public static function labels(mixed $raw): array
    {
        return array_map(
            static fn(string $key): string => self::LABELS[$key],
            self::normalize($raw)
        );
    }
}
