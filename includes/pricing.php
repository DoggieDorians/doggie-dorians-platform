<?php

declare(strict_types=1);

/**
 * Doggie Dorian's Central Pricing Logic
 *
 * Single source of truth for:
 * - Walk pricing
 * - Boarding pricing
 * - Active daycare session pricing
 * - Legacy daycare pricing (kept for reference only)
 * - Drop-ins
 * - In-home sitting
 * - Founder membership pricing
 */

function dd_pricing_matrix(): array
{
    return [
        /**
         * KEEP EXACTLY AS-IS
         */
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

        /**
         * LEGACY ONLY — kept so old pricing is not lost
         * Do not use this for new active daycare bookings.
         */
        'daycare_legacy' => [
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

        /**
         * ACTIVE DAYCARE MODEL
         * 6-hour session pricing
         */
        'daycare' => [
            'member' => [
                'base_rate' => 55.00,
                'hours' => 6,
                'food_fee' => 5.00,
                'included_walks' => 1,
                'included_walk_duration_minutes' => 30,
                'additional_walk_rate' => 10.00,
                'additional_walk_duration_minutes' => 30,
            ],
            'non_member' => [
                'base_rate' => 65.00,
                'hours' => 6,
                'food_fee' => 7.00,
                'included_walks' => 1,
                'included_walk_duration_minutes' => 30,
                'additional_walk_rate' => 15.00,
                'additional_walk_duration_minutes' => 30,
            ],
        ],

        /**
         * KEEP EXACTLY AS-IS
         */
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

        /**
         * NEW ACTIVE DROP-IN MODEL
         */
        'drop_in' => [
            'member' => [
                'hourly_rate' => 25.00,
                'walk_add_on' => 7.00,
                'max_hours' => 2,
                'walk_duration_minutes' => 30,
            ],
            'non_member' => [
                'hourly_rate' => 30.00,
                'walk_add_on' => 10.00,
                'max_hours' => 2,
                'walk_duration_minutes' => 30,
            ],
        ],

        /**
         * NEW ACTIVE IN-HOME SITTING MODEL
         */
        'sitting' => [
            'member' => [
                'base_rate' => 110.00,
                'hours' => 4,
                'included_walks' => 1,
                'included_walk_duration_minutes' => 30,
                'additional_walk_rate' => 10.00,
                'additional_walk_duration_minutes' => 30,
            ],
            'non_member' => [
                'base_rate' => 140.00,
                'hours' => 4,
                'included_walks' => 1,
                'included_walk_duration_minutes' => 30,
                'additional_walk_rate' => 15.00,
                'additional_walk_duration_minutes' => 30,
            ],
        ],
    ];
}

/**
 * Founder membership catalog.
 */
function dd_membership_catalog(): array
{
    return [
        'founder-walk-club' => [
            'slug' => 'founder-walk-club',
            'name' => 'Founder Walk Club',
            'type' => 'founder',
            'price' => 250.00,
            'value' => 300.00,
            'slots' => 20,
            'tag' => 'Founding Walk Access',
            'summary' => 'A limited founder package built for clients who mainly want recurring walks, premium access, and founder-only perks.',
            'billing_interval' => 'monthly',
            'included_services' => [
                'walk_30' => 12,
                'daycare' => 0,
                'drop_in' => 0,
                'boarding_nights' => 0,
                'sitting' => 0,
            ],
            'features' => [
                '12 included 30-minute walks each month',
                'Unused walks roll over into the following month only',
                'Priority booking & scheduling',
                'Reserved availability during peak demand',
                'Exclusive welcome gift',
                'Founder-only private contact access',
                'Locked-in founder pricing',
            ],
        ],

        'founder-care-club' => [
            'slug' => 'founder-care-club',
            'name' => 'Founder Care Club',
            'type' => 'founder',
            'price' => 499.00,
            'value' => 650.00,
            'slots' => 10,
            'tag' => 'Most Popular Founder Tier',
            'summary' => 'A premium recurring care membership for clients who want more coverage across walks, daycare, and drop-ins.',
            'billing_interval' => 'monthly',
            'included_services' => [
                'walk_30' => 16,
                'daycare' => 2,
                'drop_in' => 2,
                'boarding_nights' => 0,
                'sitting' => 0,
            ],
            'features' => [
                '16 included 30-minute walks each month',
                '2 included daycare sessions each month',
                '2 included drop-in visits each month',
                'Unused walks roll over into the following month only',
                '10% off boarding bookings',
                'Premium welcome gift',
                'Annual birthday gift for your dog',
                'Founder-only private contact access',
                'Higher founder scheduling priority',
                'Locked-in founder pricing',
            ],
        ],

        'founder-elite-club' => [
            'slug' => 'founder-elite-club',
            'name' => 'Founder Elite Club',
            'type' => 'founder',
            'price' => 899.00,
            'value' => 1100.00,
            'slots' => 5,
            'tag' => 'Highest Founder Tier',
            'summary' => 'Your most exclusive founder package for clients who want premium recurring care, boarding value, and top-tier access.',
            'billing_interval' => 'monthly',
            'included_services' => [
                'walk_30' => 20,
                'daycare' => 4,
                'drop_in' => 4,
                'boarding_nights' => 3,
                'sitting' => 0,
            ],
            'features' => [
                '20 included 30-minute walks each month',
                '4 included daycare sessions each month',
                '4 included drop-in visits each month',
                '3 complimentary boarding nights',
                'Unused walks roll over into the following month only',
                '20% off additional boarding bookings',
                'Premium welcome gift',
                'Annual birthday gift for your dog',
                'Founder-only private contact access',
                'Highest founder scheduling priority',
                'Locked-in founder pricing',
            ],
        ],
    ];
}

function dd_founder_membership_catalog(): array
{
    return dd_membership_catalog();
}

function dd_normalize_membership_slug(?string $slug): string
{
    $slug = strtolower(trim((string) $slug));

    return match ($slug) {
        'founder-walk-club', 'founder_walk_club', 'founder walk club' => 'founder-walk-club',
        'founder-care-club', 'founder_care_club', 'founder care club' => 'founder-care-club',
        'founder-elite-club', 'founder_elite_club', 'founder elite club' => 'founder-elite-club',
        default => '',
    };
}

function dd_get_membership_plan(string $slug): array
{
    $slug = dd_normalize_membership_slug($slug);
    $catalog = dd_membership_catalog();

    if ($slug === '' || !isset($catalog[$slug])) {
        throw new InvalidArgumentException('Invalid membership plan.');
    }

    return $catalog[$slug];
}

function dd_find_membership_plan(?string $slug): ?array
{
    $slug = dd_normalize_membership_slug($slug);

    if ($slug === '') {
        return null;
    }

    $catalog = dd_membership_catalog();

    return $catalog[$slug] ?? null;
}

function dd_is_membership_plan(?string $slug): bool
{
    return dd_find_membership_plan($slug) !== null;
}

function dd_is_founder_membership(?string $slug): bool
{
    $plan = dd_find_membership_plan($slug);

    return is_array($plan) && (($plan['type'] ?? '') === 'founder');
}

function dd_get_membership_price(string $slug): float
{
    $plan = dd_get_membership_plan($slug);

    return (float) ($plan['price'] ?? 0);
}

function dd_get_membership_value(string $slug): float
{
    $plan = dd_get_membership_plan($slug);

    return (float) ($plan['value'] ?? 0);
}

function dd_get_membership_slots(string $slug): int
{
    $plan = dd_get_membership_plan($slug);

    return (int) ($plan['slots'] ?? 0);
}

function dd_get_membership_pricing_summary(string $slug): array
{
    $plan = dd_get_membership_plan($slug);

    return [
        'membership_slug'   => $plan['slug'],
        'membership_name'   => $plan['name'],
        'membership_type'   => $plan['type'],
        'billing_interval'  => $plan['billing_interval'] ?? 'monthly',
        'unit_price'        => (float) $plan['price'],
        'total_price'       => (float) $plan['price'],
        'advertised_value'  => (float) $plan['value'],
        'monthly_savings'   => max(0, (float) $plan['value'] - (float) $plan['price']),
        'slots'             => (int) ($plan['slots'] ?? 0),
        'included_services' => $plan['included_services'] ?? [],
        'features'          => $plan['features'] ?? [],
    ];
}

/**
 * Normalizers
 */
function dd_normalize_dog_size(?string $dogSize): string
{
    $dogSize = strtolower(trim((string) $dogSize));

    return match ($dogSize) {
        'small', 'small dog'   => 'small',
        'medium', 'medium dog' => 'medium',
        'large', 'large dog'   => 'large',
        default                => '',
    };
}

function dd_normalize_service_type(?string $serviceType): string
{
    $serviceType = strtolower(trim((string) $serviceType));

    return match ($serviceType) {
        'walk', 'walks', 'dog walk', 'dog walking' => 'walk',
        'daycare', 'day care'                      => 'daycare',
        'boarding', 'board'                        => 'boarding',
        'drop-in', 'drop in', 'drop_in', 'dropins', 'dropin' => 'drop_in',
        'sitting', 'in-home sitting', 'in home sitting', 'in_home_sitting' => 'sitting',
        default                                    => '',
    };
}

function dd_format_money(float $amount): string
{
    return '$' . number_format($amount, 2);
}

/**
 * Walk pricing — unchanged
 */
function dd_get_walk_pricing(int $durationMinutes, bool $isMember): array
{
    $pricing = dd_pricing_matrix();
    $pricingType = $isMember ? 'member' : 'non_member';

    if (!isset($pricing['walk'][$pricingType][$durationMinutes])) {
        throw new InvalidArgumentException('Invalid walk duration.');
    }

    $unitPrice = (float) $pricing['walk'][$pricingType][$durationMinutes];

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
 * Active daycare config helper
 */
function dd_get_daycare_config(bool $isMember): array
{
    $pricing = dd_pricing_matrix();

    return $pricing['daycare'][$isMember ? 'member' : 'non_member'];
}

/**
 * Legacy daycare helper
 * Reference only — not for new active bookings unless you explicitly call it.
 */
function dd_get_daycare_legacy_pricing(string $dogSize, bool $isMember, int $days): array
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
        $discountLabel = 'member_3plus_daycare_legacy';
        $unitPrice = (float) $pricing['daycare_legacy']['member_3plus'][$dogSize];
    } elseif ($isMember) {
        $pricingType = 'member';
        $discountLabel = 'standard_member_daycare_legacy';
        $unitPrice = (float) $pricing['daycare_legacy']['member'][$dogSize];
    } else {
        $pricingType = 'non_member';
        $discountLabel = 'standard_non_member_daycare_legacy';
        $unitPrice = (float) $pricing['daycare_legacy']['non_member'][$dogSize];
    }

    return [
        'service_type'   => 'daycare_legacy',
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
 * Active daycare pricing
 */
function dd_get_daycare_pricing(bool $isMember, bool $provideFood = false, int $extraWalks = 0): array
{
    $config = dd_get_daycare_config($isMember);
    $extraWalks = max(0, $extraWalks);

    $basePrice = (float) $config['base_rate'];
    $foodFee = $provideFood ? (float) $config['food_fee'] : 0.00;
    $extraWalkCost = $extraWalks * (float) $config['additional_walk_rate'];
    $totalPrice = $basePrice + $foodFee + $extraWalkCost;

    return [
        'service_type'   => 'daycare',
        'pricing_type'   => $isMember ? 'member' : 'non_member',
        'discount_label' => $isMember ? 'member_daycare_6hr_custom' : 'non_member_daycare_6hr_custom',
        'quantity'       => 1,
        'unit_label'     => 'session',
        'unit_price'     => $basePrice,
        'total_price'    => $totalPrice,
        'duration'       => ((int) $config['hours']) * 60,
        'dog_size'       => null,
        'pricing_breakdown' => [
            'base_price' => $basePrice,
            'food_fee' => $foodFee,
            'food_provided_by_business' => $provideFood ? 1 : 0,
            'included_walks' => (int) $config['included_walks'],
            'included_walk_duration_minutes' => (int) $config['included_walk_duration_minutes'],
            'extra_walks' => $extraWalks,
            'extra_walk_rate' => (float) $config['additional_walk_rate'],
            'extra_walk_duration_minutes' => (int) $config['additional_walk_duration_minutes'],
            'extra_walk_cost' => $extraWalkCost,
            'session_hours' => (int) $config['hours'],
        ],
    ];
}

/**
 * Boarding pricing — unchanged
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
        $unitPrice = (float) $pricing['boarding']['member_5plus'][$dogSize];
    } elseif ($isMember) {
        $pricingType = 'member';
        $discountLabel = 'standard_member';
        $unitPrice = (float) $pricing['boarding']['member'][$dogSize];
    } else {
        $pricingType = 'non_member';
        $discountLabel = 'standard_non_member';
        $unitPrice = (float) $pricing['boarding']['non_member'][$dogSize];
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
 * Drop-in config helper
 */
function dd_get_drop_in_config(bool $isMember): array
{
    $pricing = dd_pricing_matrix();

    return $pricing['drop_in'][$isMember ? 'member' : 'non_member'];
}

/**
 * Drop-in pricing
 */
function dd_get_drop_in_pricing(bool $isMember, int $hours = 1, bool $addWalk = false): array
{
    $config = dd_get_drop_in_config($isMember);
    $hours = max(1, min((int) $config['max_hours'], $hours));

    $hourlyRate = (float) $config['hourly_rate'];
    $walkAddOn = $addWalk ? (float) $config['walk_add_on'] : 0.00;
    $basePrice = $hourlyRate * $hours;
    $totalPrice = $basePrice + $walkAddOn;

    return [
        'service_type'   => 'drop_in',
        'pricing_type'   => $isMember ? 'member' : 'non_member',
        'discount_label' => $isMember ? 'member_dropin_hourly_custom' : 'non_member_dropin_hourly_custom',
        'quantity'       => $hours,
        'unit_label'     => 'hour',
        'unit_price'     => $hourlyRate,
        'total_price'    => $totalPrice,
        'duration'       => $hours * 60,
        'dog_size'       => null,
        'pricing_breakdown' => [
            'base_price' => $basePrice,
            'hours' => $hours,
            'hourly_rate' => $hourlyRate,
            'walk_added' => $addWalk ? 1 : 0,
            'walk_duration_minutes' => (int) $config['walk_duration_minutes'],
            'walk_fee' => $walkAddOn,
            'max_hours' => (int) $config['max_hours'],
        ],
    ];
}

/**
 * Sitting config helper
 */
function dd_get_sitting_config(bool $isMember): array
{
    $pricing = dd_pricing_matrix();

    return $pricing['sitting'][$isMember ? 'member' : 'non_member'];
}

/**
 * In-home sitting pricing
 */
function dd_get_sitting_pricing(bool $isMember, int $extraWalks = 0): array
{
    $config = dd_get_sitting_config($isMember);
    $extraWalks = max(0, $extraWalks);

    $basePrice = (float) $config['base_rate'];
    $extraWalkCost = $extraWalks * (float) $config['additional_walk_rate'];
    $totalPrice = $basePrice + $extraWalkCost;

    return [
        'service_type'   => 'sitting',
        'pricing_type'   => $isMember ? 'member' : 'non_member',
        'discount_label' => $isMember ? 'member_in_home_sitting_custom' : 'non_member_in_home_sitting_custom',
        'quantity'       => 1,
        'unit_label'     => 'session',
        'unit_price'     => $basePrice,
        'total_price'    => $totalPrice,
        'duration'       => ((int) $config['hours']) * 60,
        'dog_size'       => null,
        'pricing_breakdown' => [
            'base_price' => $basePrice,
            'included_walks' => (int) $config['included_walks'],
            'included_walk_duration_minutes' => (int) $config['included_walk_duration_minutes'],
            'extra_walks' => $extraWalks,
            'extra_walk_rate' => (float) $config['additional_walk_rate'],
            'extra_walk_duration_minutes' => (int) $config['additional_walk_duration_minutes'],
            'extra_walk_cost' => $extraWalkCost,
            'session_hours' => (int) $config['hours'],
        ],
    ];
}

/**
 * Main pricing router
 */
function dd_get_service_pricing(string $serviceType, bool $isMember, array $options = []): array
{
    $serviceType = dd_normalize_service_type($serviceType);

    return match ($serviceType) {
        'walk' => dd_get_walk_pricing(
            (int) ($options['duration_minutes'] ?? 0),
            $isMember
        ),
        'daycare' => dd_get_daycare_pricing(
            $isMember,
            !empty($options['provide_food']),
            (int) ($options['extra_walks'] ?? 0)
        ),
        'boarding' => dd_get_boarding_pricing(
            (string) ($options['dog_size'] ?? ''),
            $isMember,
            (int) ($options['quantity'] ?? 0)
        ),
        'drop_in' => dd_get_drop_in_pricing(
            $isMember,
            (int) ($options['quantity'] ?? 1),
            !empty($options['add_walk'])
        ),
        'sitting' => dd_get_sitting_pricing(
            $isMember,
            (int) ($options['extra_walks'] ?? 0)
        ),
        default => throw new InvalidArgumentException('Invalid service type.'),
    };
}

/**
 * Legacy helper kept for compatibility
 */
function dd_get_legacy_daycare_pricing(string $dogSize, bool $isMember, int $days): array
{
    return dd_get_daycare_legacy_pricing($dogSize, $isMember, $days);
}

/**
 * Date helpers
 */
function dd_calculate_daycare_days(string $startDate, string $endDate): int
{
    $start = new DateTime($startDate);
    $end   = new DateTime($endDate);

    if ($end < $start) {
        throw new InvalidArgumentException('End date cannot be before start date.');
    }

    $interval = $start->diff($end);

    return ((int) $interval->days) + 1;
}

function dd_calculate_boarding_nights(string $checkInDate, string $checkOutDate): int
{
    $checkIn  = new DateTime($checkInDate);
    $checkOut = new DateTime($checkOutDate);

    if ($checkOut <= $checkIn) {
        throw new InvalidArgumentException('Check-out date must be after check-in date.');
    }

    $interval = $checkIn->diff($checkOut);

    return (int) $interval->days;
}