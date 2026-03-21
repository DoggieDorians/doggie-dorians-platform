<?php

declare(strict_types=1);

/**
 * Doggie Dorian's Central Pricing Logic
 *
 * This file is the single source of truth for:
 * - Walk pricing
 * - Daycare pricing
 * - Boarding pricing
 * - Member vs non-member pricing
 * - Volume discounts for members
 */

function dd_pricing_matrix(): array
{
    return [
        'walk' => [
            'non_member' => [
                15 => 23.00,
                20 => 25.00,
                30 => 30.00,
                45 => 38.00,
                60 => 42.00,
            ],
            'member' => [
                15 => 20.00,
                20 => 22.00,
                30 => 25.00,
                45 => 32.00,
                60 => 35.00,
            ],
        ],

        'daycare' => [
            'non_member' => [
                'small'  => 65.00,
                'medium' => 85.00,
                'large'  => 110.00,
            ],
            'member' => [
                'small'  => 55.00,
                'medium' => 70.00,
                'large'  => 90.00,
            ],
            'member_3plus' => [
                'small'  => 50.00,
                'medium' => 65.00,
                'large'  => 82.00,
            ],
        ],

        'boarding' => [
            'non_member' => [
                'small'  => 90.00,
                'medium' => 110.00,
                'large'  => 120.00,
            ],
            'member' => [
                'small'  => 75.00,
                'medium' => 90.00,
                'large'  => 100.00,
            ],
            'member_5plus' => [
                'small'  => 68.00,
                'medium' => 82.00,
                'large'  => 92.00,
            ],
        ],
    ];
}

/**
 * Standardize dog size input.
 */
function dd_normalize_dog_size(?string $dogSize): string
{
    $dogSize = strtolower(trim((string)$dogSize));

    return match ($dogSize) {
        'small', 'small dog'   => 'small',
        'medium', 'medium dog' => 'medium',
        'large', 'large dog'   => 'large',
        default                => '',
    };
}

/**
 * Standardize service type input.
 */
function dd_normalize_service_type(?string $serviceType): string
{
    $serviceType = strtolower(trim((string)$serviceType));

    return match ($serviceType) {
        'walk', 'walks', 'dog walk', 'dog walking' => 'walk',
        'daycare', 'day care'                      => 'daycare',
        'boarding', 'board'                        => 'boarding',
        default                                    => '',
    };
}

/**
 * Convert values to money format.
 */
function dd_format_money(float $amount): string
{
    return '$' . number_format($amount, 2);
}

/**
 * Get walk pricing.
 */
function dd_get_walk_pricing(int $durationMinutes, bool $isMember): array
{
    $pricing = dd_pricing_matrix();
    $pricingType = $isMember ? 'member' : 'non_member';

    if (!isset($pricing['walk'][$pricingType][$durationMinutes])) {
        throw new InvalidArgumentException('Invalid walk duration.');
    }

    $unitPrice = (float)$pricing['walk'][$pricingType][$durationMinutes];

    return [
        'service_type'   => 'walk',
        'pricing_type'   => $pricingType,
        'discount_label' => $isMember ? 'standard_member' : 'standard_non_member',
        'quantity'       => 1,
        'unit_label'     => 'walk',
        'unit_price'     => $unitPrice,
        'total_price'    => $unitPrice,
        'duration'       => $durationMinutes,
        'dog_size'       => null,
    ];
}

/**
 * Get daycare pricing.
 */
function dd_get_daycare_pricing(string $dogSize, bool $isMember, int $days): array
{
    $pricing = dd_pricing_matrix();
    $dogSize = dd_normalize_dog_size($dogSize);

    if ($dogSize === '') {
        throw new InvalidArgumentException('Invalid dog size for daycare.');
    }

    if ($days < 1) {
        throw new InvalidArgumentException('Daycare days must be at least 1.');
    }

    if ($isMember && $days >= 3) {
        $pricingType = 'member';
        $discountLabel = 'member_3plus_daycare';
        $unitPrice = (float)$pricing['daycare']['member_3plus'][$dogSize];
    } elseif ($isMember) {
        $pricingType = 'member';
        $discountLabel = 'standard_member';
        $unitPrice = (float)$pricing['daycare']['member'][$dogSize];
    } else {
        $pricingType = 'non_member';
        $discountLabel = 'standard_non_member';
        $unitPrice = (float)$pricing['daycare']['non_member'][$dogSize];
    }

    return [
        'service_type'   => 'daycare',
        'pricing_type'   => $pricingType,
        'discount_label' => $discountLabel,
        'quantity'       => $days,
        'unit_label'     => 'day',
        'unit_price'     => $unitPrice,
        'total_price'    => $unitPrice * $days,
        'duration'       => null,
        'dog_size'       => $dogSize,
    ];
}

/**
 * Get boarding pricing.
 */
function dd_get_boarding_pricing(string $dogSize, bool $isMember, int $nights): array
{
    $pricing = dd_pricing_matrix();
    $dogSize = dd_normalize_dog_size($dogSize);

    if ($dogSize === '') {
        throw new InvalidArgumentException('Invalid dog size for boarding.');
    }

    if ($nights < 1) {
        throw new InvalidArgumentException('Boarding nights must be at least 1.');
    }

    if ($isMember && $nights >= 5) {
        $pricingType = 'member';
        $discountLabel = 'member_5plus_boarding';
        $unitPrice = (float)$pricing['boarding']['member_5plus'][$dogSize];
    } elseif ($isMember) {
        $pricingType = 'member';
        $discountLabel = 'standard_member';
        $unitPrice = (float)$pricing['boarding']['member'][$dogSize];
    } else {
        $pricingType = 'non_member';
        $discountLabel = 'standard_non_member';
        $unitPrice = (float)$pricing['boarding']['non_member'][$dogSize];
    }

    return [
        'service_type'   => 'boarding',
        'pricing_type'   => $pricingType,
        'discount_label' => $discountLabel,
        'quantity'       => $nights,
        'unit_label'     => 'night',
        'unit_price'     => $unitPrice,
        'total_price'    => $unitPrice * $nights,
        'duration'       => null,
        'dog_size'       => $dogSize,
    ];
}

/**
 * Main pricing router.
 *
 * Example:
 * dd_get_service_pricing('walk', true, [
 *   'duration_minutes' => 30
 * ]);
 *
 * dd_get_service_pricing('daycare', true, [
 *   'dog_size' => 'medium',
 *   'quantity' => 4
 * ]);
 *
 * dd_get_service_pricing('boarding', false, [
 *   'dog_size' => 'large',
 *   'quantity' => 2
 * ]);
 */
function dd_get_service_pricing(string $serviceType, bool $isMember, array $options = []): array
{
    $serviceType = dd_normalize_service_type($serviceType);

    return match ($serviceType) {
        'walk' => dd_get_walk_pricing(
            (int)($options['duration_minutes'] ?? 0),
            $isMember
        ),
        'daycare' => dd_get_daycare_pricing(
            (string)($options['dog_size'] ?? ''),
            $isMember,
            (int)($options['quantity'] ?? 0)
        ),
        'boarding' => dd_get_boarding_pricing(
            (string)($options['dog_size'] ?? ''),
            $isMember,
            (int)($options['quantity'] ?? 0)
        ),
        default => throw new InvalidArgumentException('Invalid service type.'),
    };
}

/**
 * Calculate inclusive daycare days from start/end dates.
 * Example:
 * 2026-04-01 to 2026-04-03 = 3 days
 */
function dd_calculate_daycare_days(string $startDate, string $endDate): int
{
    $start = new DateTime($startDate);
    $end   = new DateTime($endDate);

    if ($end < $start) {
        throw new InvalidArgumentException('End date cannot be before start date.');
    }

    $interval = $start->diff($end);

    return ((int)$interval->days) + 1;
}

/**
 * Calculate boarding nights from check-in/check-out.
 * Example:
 * 2026-04-01 to 2026-04-06 = 5 nights
 */
function dd_calculate_boarding_nights(string $checkInDate, string $checkOutDate): int
{
    $checkIn  = new DateTime($checkInDate);
    $checkOut = new DateTime($checkOutDate);

    if ($checkOut <= $checkIn) {
        throw new InvalidArgumentException('Check-out date must be after check-in date.');
    }

    $interval = $checkIn->diff($checkOut);

    return (int)$interval->days;
}