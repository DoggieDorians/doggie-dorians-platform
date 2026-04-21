<?php
declare(strict_types=1);

if (!function_exists('memberBadgeRoadmapCatalogDetailed')) {
    function memberBadgeRoadmapCatalogDetailed()
    {
        return array(
            'walk_first_walk' => array(
                'badge_key' => 'walk_first_walk',
                'badge_name' => 'First Walk',
                'badge_mark' => '1W',
                'badge_group' => 'service',
                'badge_family' => 'walk_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-walk',
                'description' => 'Unlocked after the first walk recorded across the member account.',
                'reward_title' => 'Walk Reward Slot',
                'reward_note' => 'Ready for future walk credits, route perks, or luxury surprises.',
                'unlock_metric' => 'walk_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 1,
            ),
            'walk_stroll_starter' => array(
                'badge_key' => 'walk_stroll_starter',
                'badge_name' => 'Stroll Starter',
                'badge_mark' => '5W',
                'badge_group' => 'service',
                'badge_family' => 'walk_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-walk',
                'description' => 'Awarded once five walks have been recorded across the account.',
                'reward_title' => 'Walk Reward Slot',
                'reward_note' => 'Ready for future walk credits, route perks, or luxury surprises.',
                'unlock_metric' => 'walk_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 5,
            ),
            'walk_sidewalk_regular' => array(
                'badge_key' => 'walk_sidewalk_regular',
                'badge_name' => 'Sidewalk Regular',
                'badge_mark' => '10W',
                'badge_group' => 'service',
                'badge_family' => 'walk_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-walk',
                'description' => 'Marks an account with a dependable ten-walk rhythm.',
                'reward_title' => 'Walk Reward Slot',
                'reward_note' => 'Ready for future walk credits, route perks, or luxury surprises.',
                'unlock_metric' => 'walk_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 10,
            ),
            'walk_city_strider' => array(
                'badge_key' => 'walk_city_strider',
                'badge_name' => 'City Strider',
                'badge_mark' => '20W',
                'badge_group' => 'service',
                'badge_family' => 'walk_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-walk',
                'description' => 'Reserved for members who reach twenty recorded walks.',
                'reward_title' => 'Walk Reward Slot',
                'reward_note' => 'Ready for future walk credits, route perks, or luxury surprises.',
                'unlock_metric' => 'walk_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 20,
            ),
            'walk_pack_pace_pro' => array(
                'badge_key' => 'walk_pack_pace_pro',
                'badge_name' => 'Pack Pace Pro',
                'badge_mark' => '35W',
                'badge_group' => 'service',
                'badge_family' => 'walk_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-walk',
                'description' => 'Unlocked after thirty-five walks have been logged.',
                'reward_title' => 'Walk Reward Slot',
                'reward_note' => 'Ready for future walk credits, route perks, or luxury surprises.',
                'unlock_metric' => 'walk_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 35,
            ),
            'walk_golden_leash' => array(
                'badge_key' => 'walk_golden_leash',
                'badge_name' => 'Golden Leash',
                'badge_mark' => '50W',
                'badge_group' => 'service',
                'badge_family' => 'walk_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-walk',
                'description' => 'A premium walk collection badge for fifty recorded walks.',
                'reward_title' => 'Walk Reward Slot',
                'reward_note' => 'Ready for future walk credits, route perks, or luxury surprises.',
                'unlock_metric' => 'walk_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 50,
            ),
            'walk_park_route_royalty' => array(
                'badge_key' => 'walk_park_route_royalty',
                'badge_name' => 'Park Route Royalty',
                'badge_mark' => '75W',
                'badge_group' => 'service',
                'badge_family' => 'walk_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-walk',
                'description' => 'Unlocked when seventy-five walks have been completed in the collection.',
                'reward_title' => 'Walk Reward Slot',
                'reward_note' => 'Ready for future walk credits, route perks, or luxury surprises.',
                'unlock_metric' => 'walk_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 75,
            ),
            'walk_walk_legend' => array(
                'badge_key' => 'walk_walk_legend',
                'badge_name' => 'Walk Legend',
                'badge_mark' => '100W',
                'badge_group' => 'service',
                'badge_family' => 'walk_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-walk',
                'description' => 'The top walk collection distinction at one hundred recorded walks.',
                'reward_title' => 'Walk Reward Slot',
                'reward_note' => 'Ready for future walk credits, route perks, or luxury surprises.',
                'unlock_metric' => 'walk_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 100,
            ),

            'daycare_daycare_debut' => array(
                'badge_key' => 'daycare_daycare_debut',
                'badge_name' => 'Daycare Debut',
                'badge_mark' => '1D',
                'badge_group' => 'service',
                'badge_family' => 'daycare_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-daycare',
                'description' => 'Unlocked after the first daycare session is recorded.',
                'reward_title' => 'Daycare Reward Slot',
                'reward_note' => 'Ready for future daycare upgrades, lounge perks, or surprise extras.',
                'unlock_metric' => 'daycare_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 1,
            ),
            'daycare_social_pup' => array(
                'badge_key' => 'daycare_social_pup',
                'badge_name' => 'Social Pup',
                'badge_mark' => '3D',
                'badge_group' => 'service',
                'badge_family' => 'daycare_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-daycare',
                'description' => 'A social daycare milestone unlocked at three sessions.',
                'reward_title' => 'Daycare Reward Slot',
                'reward_note' => 'Ready for future daycare upgrades, lounge perks, or surprise extras.',
                'unlock_metric' => 'daycare_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 3,
            ),
            'daycare_playroom_regular' => array(
                'badge_key' => 'daycare_playroom_regular',
                'badge_name' => 'Playroom Regular',
                'badge_mark' => '5D',
                'badge_group' => 'service',
                'badge_family' => 'daycare_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-daycare',
                'description' => 'Unlocked after five daycare sessions are on record.',
                'reward_title' => 'Daycare Reward Slot',
                'reward_note' => 'Ready for future daycare upgrades, lounge perks, or surprise extras.',
                'unlock_metric' => 'daycare_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 5,
            ),
            'daycare_lounge_favorite' => array(
                'badge_key' => 'daycare_lounge_favorite',
                'badge_name' => 'Lounge Favorite',
                'badge_mark' => '10D',
                'badge_group' => 'service',
                'badge_family' => 'daycare_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-daycare',
                'description' => 'Reserved for members with ten daycare sessions recorded.',
                'reward_title' => 'Daycare Reward Slot',
                'reward_note' => 'Ready for future daycare upgrades, lounge perks, or surprise extras.',
                'unlock_metric' => 'daycare_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 10,
            ),
            'daycare_day_retreat_vip' => array(
                'badge_key' => 'daycare_day_retreat_vip',
                'badge_name' => 'Day Retreat VIP',
                'badge_mark' => '20D',
                'badge_group' => 'service',
                'badge_family' => 'daycare_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-daycare',
                'description' => 'Unlocked once twenty daycare sessions have been recorded.',
                'reward_title' => 'Daycare Reward Slot',
                'reward_note' => 'Ready for future daycare upgrades, lounge perks, or surprise extras.',
                'unlock_metric' => 'daycare_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 20,
            ),
            'daycare_daycare_icon' => array(
                'badge_key' => 'daycare_daycare_icon',
                'badge_name' => 'Daycare Icon',
                'badge_mark' => '35D',
                'badge_group' => 'service',
                'badge_family' => 'daycare_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-daycare',
                'description' => 'The signature daycare collection badge at thirty-five sessions.',
                'reward_title' => 'Daycare Reward Slot',
                'reward_note' => 'Ready for future daycare upgrades, lounge perks, or surprise extras.',
                'unlock_metric' => 'daycare_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 35,
            ),

            'boarding_first_sleepover' => array(
                'badge_key' => 'boarding_first_sleepover',
                'badge_name' => 'First Sleepover',
                'badge_mark' => '1B',
                'badge_group' => 'service',
                'badge_family' => 'boarding_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-boarding',
                'description' => 'Unlocked after the first recorded boarding night.',
                'reward_title' => 'Boarding Reward Slot',
                'reward_note' => 'Ready for future boarding upgrades, suite perks, or overnight gifts.',
                'unlock_metric' => 'boarding_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 1,
            ),
            'boarding_overnight_guest' => array(
                'badge_key' => 'boarding_overnight_guest',
                'badge_name' => 'Overnight Guest',
                'badge_mark' => '3B',
                'badge_group' => 'service',
                'badge_family' => 'boarding_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-boarding',
                'description' => 'Awarded once three boarding nights are on record.',
                'reward_title' => 'Boarding Reward Slot',
                'reward_note' => 'Ready for future boarding upgrades, suite perks, or overnight gifts.',
                'unlock_metric' => 'boarding_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 3,
            ),
            'boarding_weekend_resident' => array(
                'badge_key' => 'boarding_weekend_resident',
                'badge_name' => 'Weekend Resident',
                'badge_mark' => '5B',
                'badge_group' => 'service',
                'badge_family' => 'boarding_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-boarding',
                'description' => 'Unlocked after five boarding nights have been recorded.',
                'reward_title' => 'Boarding Reward Slot',
                'reward_note' => 'Ready for future boarding upgrades, suite perks, or overnight gifts.',
                'unlock_metric' => 'boarding_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 5,
            ),
            'boarding_trusted_house_guest' => array(
                'badge_key' => 'boarding_trusted_house_guest',
                'badge_name' => 'Trusted House Guest',
                'badge_mark' => '10B',
                'badge_group' => 'service',
                'badge_family' => 'boarding_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-boarding',
                'description' => 'Reserved for members with ten boarding nights in the vault.',
                'reward_title' => 'Boarding Reward Slot',
                'reward_note' => 'Ready for future boarding upgrades, suite perks, or overnight gifts.',
                'unlock_metric' => 'boarding_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 10,
            ),
            'boarding_suite_favorite' => array(
                'badge_key' => 'boarding_suite_favorite',
                'badge_name' => 'Suite Favorite',
                'badge_mark' => '20B',
                'badge_group' => 'service',
                'badge_family' => 'boarding_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-boarding',
                'description' => 'Unlocked once twenty boarding nights have been recorded.',
                'reward_title' => 'Boarding Reward Slot',
                'reward_note' => 'Ready for future boarding upgrades, suite perks, or overnight gifts.',
                'unlock_metric' => 'boarding_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 20,
            ),
            'boarding_boarding_royalty' => array(
                'badge_key' => 'boarding_boarding_royalty',
                'badge_name' => 'Boarding Royalty',
                'badge_mark' => '35B',
                'badge_group' => 'service',
                'badge_family' => 'boarding_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-boarding',
                'description' => 'The top boarding collection badge at thirty-five boarding nights.',
                'reward_title' => 'Boarding Reward Slot',
                'reward_note' => 'Ready for future boarding upgrades, suite perks, or overnight gifts.',
                'unlock_metric' => 'boarding_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 35,
            ),

            'dropin_quick_visit' => array(
                'badge_key' => 'dropin_quick_visit',
                'badge_name' => 'Quick Visit',
                'badge_mark' => '1I',
                'badge_group' => 'service',
                'badge_family' => 'drop_in_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-dropin',
                'description' => 'Unlocked after the first recorded drop-in service.',
                'reward_title' => 'Drop-In Reward Slot',
                'reward_note' => 'Ready for future drop-in bonuses, concierge access, or surprise add-ons.',
                'unlock_metric' => 'drop_in_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 1,
            ),
            'dropin_doorstep_regular' => array(
                'badge_key' => 'dropin_doorstep_regular',
                'badge_name' => 'Doorstep Regular',
                'badge_mark' => '5I',
                'badge_group' => 'service',
                'badge_family' => 'drop_in_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-dropin',
                'description' => 'Awarded once five drop-ins have been recorded.',
                'reward_title' => 'Drop-In Reward Slot',
                'reward_note' => 'Ready for future drop-in bonuses, concierge access, or surprise add-ons.',
                'unlock_metric' => 'drop_in_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 5,
            ),
            'dropin_check_in_champ' => array(
                'badge_key' => 'dropin_check_in_champ',
                'badge_name' => 'Check-In Champ',
                'badge_mark' => '10I',
                'badge_group' => 'service',
                'badge_family' => 'drop_in_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-dropin',
                'description' => 'Unlocked after ten drop-ins are on record.',
                'reward_title' => 'Drop-In Reward Slot',
                'reward_note' => 'Ready for future drop-in bonuses, concierge access, or surprise add-ons.',
                'unlock_metric' => 'drop_in_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 10,
            ),
            'dropin_midday_hero' => array(
                'badge_key' => 'dropin_midday_hero',
                'badge_name' => 'Midday Hero',
                'badge_mark' => '20I',
                'badge_group' => 'service',
                'badge_family' => 'drop_in_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-dropin',
                'description' => 'Reserved for members who reach twenty recorded drop-ins.',
                'reward_title' => 'Drop-In Reward Slot',
                'reward_note' => 'Ready for future drop-in bonuses, concierge access, or surprise add-ons.',
                'unlock_metric' => 'drop_in_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 20,
            ),
            'dropin_concierge_caller' => array(
                'badge_key' => 'dropin_concierge_caller',
                'badge_name' => 'Concierge Caller',
                'badge_mark' => '35I',
                'badge_group' => 'service',
                'badge_family' => 'drop_in_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-dropin',
                'description' => 'Unlocked after thirty-five drop-ins have been recorded.',
                'reward_title' => 'Drop-In Reward Slot',
                'reward_note' => 'Ready for future drop-in bonuses, concierge access, or surprise add-ons.',
                'unlock_metric' => 'drop_in_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 35,
            ),
            'dropin_drop_in_elite' => array(
                'badge_key' => 'dropin_drop_in_elite',
                'badge_name' => 'Drop-In Elite',
                'badge_mark' => '50I',
                'badge_group' => 'service',
                'badge_family' => 'drop_in_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-dropin',
                'description' => 'The highest drop-in collection distinction at fifty recorded visits.',
                'reward_title' => 'Drop-In Reward Slot',
                'reward_note' => 'Ready for future drop-in bonuses, concierge access, or surprise add-ons.',
                'unlock_metric' => 'drop_in_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 50,
            ),

            'sitting_sitting_debut' => array(
                'badge_key' => 'sitting_sitting_debut',
                'badge_name' => 'Sitting Debut',
                'badge_mark' => '1S',
                'badge_group' => 'service',
                'badge_family' => 'sitting_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-sitting',
                'description' => 'Unlocked after the first sitting service is recorded.',
                'reward_title' => 'Sitting Reward Slot',
                'reward_note' => 'Ready for future sitting perks, comfort upgrades, or home-care extras.',
                'unlock_metric' => 'sitting_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 1,
            ),
            'sitting_calm_companion' => array(
                'badge_key' => 'sitting_calm_companion',
                'badge_name' => 'Calm Companion',
                'badge_mark' => '3S',
                'badge_group' => 'service',
                'badge_family' => 'sitting_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-sitting',
                'description' => 'Awarded once three sitting services have been recorded.',
                'reward_title' => 'Sitting Reward Slot',
                'reward_note' => 'Ready for future sitting perks, comfort upgrades, or home-care extras.',
                'unlock_metric' => 'sitting_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 3,
            ),
            'sitting_home_comfort_pup' => array(
                'badge_key' => 'sitting_home_comfort_pup',
                'badge_name' => 'Home Comfort Pup',
                'badge_mark' => '5S',
                'badge_group' => 'service',
                'badge_family' => 'sitting_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-sitting',
                'description' => 'Unlocked after five sitting services are on record.',
                'reward_title' => 'Sitting Reward Slot',
                'reward_note' => 'Ready for future sitting perks, comfort upgrades, or home-care extras.',
                'unlock_metric' => 'sitting_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 5,
            ),
            'sitting_trusted_stay_star' => array(
                'badge_key' => 'sitting_trusted_stay_star',
                'badge_name' => 'Trusted Stay Star',
                'badge_mark' => '10S',
                'badge_group' => 'service',
                'badge_family' => 'sitting_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-sitting',
                'description' => 'Reserved for members with ten sitting services recorded.',
                'reward_title' => 'Sitting Reward Slot',
                'reward_note' => 'Ready for future sitting perks, comfort upgrades, or home-care extras.',
                'unlock_metric' => 'sitting_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 10,
            ),
            'sitting_house_harmony' => array(
                'badge_key' => 'sitting_house_harmony',
                'badge_name' => 'House Harmony',
                'badge_mark' => '20S',
                'badge_group' => 'service',
                'badge_family' => 'sitting_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-sitting',
                'description' => 'Unlocked once twenty sitting services have been recorded.',
                'reward_title' => 'Sitting Reward Slot',
                'reward_note' => 'Ready for future sitting perks, comfort upgrades, or home-care extras.',
                'unlock_metric' => 'sitting_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 20,
            ),
            'sitting_sitting_prestige' => array(
                'badge_key' => 'sitting_sitting_prestige',
                'badge_name' => 'Sitting Prestige',
                'badge_mark' => '35S',
                'badge_group' => 'service',
                'badge_family' => 'sitting_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-sitting',
                'description' => 'The signature sitting collection distinction at thirty-five services.',
                'reward_title' => 'Sitting Reward Slot',
                'reward_note' => 'Ready for future sitting perks, comfort upgrades, or home-care extras.',
                'unlock_metric' => 'sitting_total',
                'unlock_operator' => '>=',
                'unlock_threshold' => 35,
            ),

            'multi_service_explorer' => array(
                'badge_key' => 'multi_service_explorer',
                'badge_name' => 'Service Explorer',
                'badge_mark' => '2X',
                'badge_group' => 'service',
                'badge_family' => 'multi_service_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-multi',
                'description' => 'Unlocked after using two different service families.',
                'reward_title' => 'Service Mix Reward Slot',
                'reward_note' => 'Ready for future mix-and-match perks, surprise credits, or concierge access.',
                'unlock_metric' => 'service_types_used',
                'unlock_operator' => '>=',
                'unlock_threshold' => 2,
            ),
            'multi_well_rounded_pup' => array(
                'badge_key' => 'multi_well_rounded_pup',
                'badge_name' => 'Well-Rounded Pup',
                'badge_mark' => '3X',
                'badge_group' => 'service',
                'badge_family' => 'multi_service_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-multi',
                'description' => 'Awarded after three different service families have been used.',
                'reward_title' => 'Service Mix Reward Slot',
                'reward_note' => 'Ready for future mix-and-match perks, surprise credits, or concierge access.',
                'unlock_metric' => 'service_types_used',
                'unlock_operator' => '>=',
                'unlock_threshold' => 3,
            ),
            'multi_full_care_companion' => array(
                'badge_key' => 'multi_full_care_companion',
                'badge_name' => 'Full Care Companion',
                'badge_mark' => '4X',
                'badge_group' => 'service',
                'badge_family' => 'multi_service_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-multi',
                'description' => 'Unlocked after four different service families have been used.',
                'reward_title' => 'Service Mix Reward Slot',
                'reward_note' => 'Ready for future mix-and-match perks, surprise credits, or concierge access.',
                'unlock_metric' => 'service_types_used',
                'unlock_operator' => '>=',
                'unlock_threshold' => 4,
            ),
            'multi_signature_lifestyle' => array(
                'badge_key' => 'multi_signature_lifestyle',
                'badge_name' => 'Signature Lifestyle',
                'badge_mark' => '5X',
                'badge_group' => 'service',
                'badge_family' => 'multi_service_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-service-multi',
                'description' => 'The signature cross-service badge once all five tracked service families have been used.',
                'reward_title' => 'Service Mix Reward Slot',
                'reward_note' => 'Ready for future mix-and-match perks, surprise credits, or concierge access.',
                'unlock_metric' => 'service_types_used',
                'unlock_operator' => '>=',
                'unlock_threshold' => 5,
            ),

            'loyalty_one_month_loyal' => array(
                'badge_key' => 'loyalty_one_month_loyal',
                'badge_name' => 'One Month Loyal',
                'badge_mark' => '1M',
                'badge_group' => 'loyalty',
                'badge_family' => 'loyalty_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-loyalty',
                'description' => 'Unlocked after one month as an active member.',
                'reward_title' => 'Loyalty Reward Slot',
                'reward_note' => 'Ready for future loyalty gifts, welcome treats, or members-only unlocks.',
                'unlock_metric' => 'months_active',
                'unlock_operator' => '>=',
                'unlock_threshold' => 1,
            ),
            'loyalty_three_month_routine' => array(
                'badge_key' => 'loyalty_three_month_routine',
                'badge_name' => 'Three Month Routine',
                'badge_mark' => '3M',
                'badge_group' => 'loyalty',
                'badge_family' => 'loyalty_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-loyalty',
                'description' => 'Awarded after three months of member history.',
                'reward_title' => 'Loyalty Reward Slot',
                'reward_note' => 'Ready for future loyalty gifts, welcome treats, or members-only unlocks.',
                'unlock_metric' => 'months_active',
                'unlock_operator' => '>=',
                'unlock_threshold' => 3,
            ),
            'loyalty_six_month_favorite' => array(
                'badge_key' => 'loyalty_six_month_favorite',
                'badge_name' => 'Six Month Favorite',
                'badge_mark' => '6M',
                'badge_group' => 'loyalty',
                'badge_family' => 'loyalty_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-loyalty',
                'description' => 'Unlocked after six months of member history.',
                'reward_title' => 'Loyalty Reward Slot',
                'reward_note' => 'Ready for future loyalty gifts, welcome treats, or members-only unlocks.',
                'unlock_metric' => 'months_active',
                'unlock_operator' => '>=',
                'unlock_threshold' => 6,
            ),
            'loyalty_one_year_member' => array(
                'badge_key' => 'loyalty_one_year_member',
                'badge_name' => 'One Year Member',
                'badge_mark' => '1Y',
                'badge_group' => 'loyalty',
                'badge_family' => 'loyalty_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-loyalty',
                'description' => 'A premium loyalty badge unlocked after twelve months of member history.',
                'reward_title' => 'Loyalty Reward Slot',
                'reward_note' => 'Ready for future loyalty gifts, welcome treats, or members-only unlocks.',
                'unlock_metric' => 'months_active',
                'unlock_operator' => '>=',
                'unlock_threshold' => 12,
            ),
            'loyalty_renewal_one' => array(
                'badge_key' => 'loyalty_renewal_one',
                'badge_name' => 'Renewal One',
                'badge_mark' => 'R1',
                'badge_group' => 'loyalty',
                'badge_family' => 'loyalty_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-loyalty',
                'description' => 'Unlocked after the first recorded membership renewal.',
                'reward_title' => 'Loyalty Reward Slot',
                'reward_note' => 'Ready for future loyalty gifts, welcome treats, or members-only unlocks.',
                'unlock_metric' => 'renewal_count',
                'unlock_operator' => '>=',
                'unlock_threshold' => 1,
            ),
            'loyalty_renewal_three' => array(
                'badge_key' => 'loyalty_renewal_three',
                'badge_name' => 'Renewal Three',
                'badge_mark' => 'R3',
                'badge_group' => 'loyalty',
                'badge_family' => 'loyalty_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-loyalty',
                'description' => 'Unlocked after the third recorded membership renewal.',
                'reward_title' => 'Loyalty Reward Slot',
                'reward_note' => 'Ready for future loyalty gifts, welcome treats, or members-only unlocks.',
                'unlock_metric' => 'renewal_count',
                'unlock_operator' => '>=',
                'unlock_threshold' => 3,
            ),
            'loyalty_renewal_six' => array(
                'badge_key' => 'loyalty_renewal_six',
                'badge_name' => 'Renewal Six',
                'badge_mark' => 'R6',
                'badge_group' => 'loyalty',
                'badge_family' => 'loyalty_collection',
                'badge_scope' => 'member',
                'theme_class' => 'badge-tier-loyalty',
                'description' => 'The top loyalty renewal badge after six recorded renewals.',
                'reward_title' => 'Loyalty Reward Slot',
                'reward_note' => 'Ready for future loyalty gifts, welcome treats, or members-only unlocks.',
                'unlock_metric' => 'renewal_count',
                'unlock_operator' => '>=',
                'unlock_threshold' => 6,
            ),
        );
    }
}

if (!function_exists('memberBadgeRoadmapSectionDefinitions')) {
    function memberBadgeRoadmapSectionDefinitions()
    {
        return array(
            'walk_collection' => 'Walk Collection',
            'daycare_collection' => 'Daycare Collection',
            'boarding_collection' => 'Boarding Collection',
            'drop_in_collection' => 'Drop-In Collection',
            'sitting_collection' => 'Sitting Collection',
            'multi_service_collection' => 'Service Mix Collection',
            'loyalty_collection' => 'Loyalty Collection',
        );
    }
}

if (!function_exists('memberBadgeMonthsSinceDate')) {
    function memberBadgeMonthsSinceDate($date)
    {
        $date = trim((string) $date);
        if ($date === '') {
            return 0;
        }

        try {
            $start = new DateTimeImmutable($date);
            $end = new DateTimeImmutable(date('Y-m-d'));
            if ($start > $end) {
                return 0;
            }

            $diff = $start->diff($end);
            return max(0, ((int) $diff->y * 12) + (int) $diff->m);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('buildMemberBadgeProgressSnapshot')) {
    function buildMemberBadgeProgressSnapshot(array $journeyCards, array $bookings, array $membershipSummary = array(), $memberCreatedAt = '')
    {
        $serviceTotals = array(
            'walk' => 0,
            'daycare' => 0,
            'boarding_total' => 0,
            'drop_in' => 0,
            'sitting' => 0,
        );

        foreach ($journeyCards as $card) {
            $counts = (array) valueFromRow((array) $card, array('counts'), array());
            $serviceTotals['walk'] += (int) valueFromRow($counts, array('walk'), 0);
            $serviceTotals['daycare'] += (int) valueFromRow($counts, array('daycare'), 0);
            $serviceTotals['boarding_total'] += (int) valueFromRow($counts, array('boarding_night'), 0);
            $serviceTotals['drop_in'] += (int) valueFromRow($counts, array('drop_in'), 0);
            $serviceTotals['sitting'] += (int) valueFromRow($counts, array('sitting'), 0);
        }

        $serviceTypesUsed = 0;
        foreach ($serviceTotals as $metric => $total) {
            if ((int) $total > 0) {
                $serviceTypesUsed++;
            }
        }

        $weekendServices = 0;
        $morningServices = 0;
        $eveningServices = 0;

        foreach ($bookings as $booking) {
            $status = strtolower(trim((string) valueFromRow((array) $booking, array('status'), '')));
            if ($status === 'cancelled' || $status === 'canceled') {
                continue;
            }

            $serviceDate = trim((string) valueFromRow((array) $booking, array('service_date', 'display_date'), ''));
            if ($serviceDate !== '') {
                $dateTs = strtotime($serviceDate);
                if ($dateTs !== false) {
                    $dayOfWeek = (int) date('N', $dateTs);
                    if ($dayOfWeek >= 6) {
                        $weekendServices++;
                    }
                }
            }

            $serviceTime = trim((string) valueFromRow((array) $booking, array('service_time', 'display_time'), ''));
            if ($serviceTime !== '') {
                $timeTs = strtotime($serviceTime);
                if ($timeTs !== false) {
                    $hour = (int) date('G', $timeTs);
                    if ($hour < 12) {
                        $morningServices++;
                    }
                    if ($hour >= 16) {
                        $eveningServices++;
                    }
                }
            }
        }

        return array(
            'walk_total' => (int) $serviceTotals['walk'],
            'daycare_total' => (int) $serviceTotals['daycare'],
            'boarding_total' => (int) $serviceTotals['boarding_total'],
            'drop_in_total' => (int) $serviceTotals['drop_in'],
            'sitting_total' => (int) $serviceTotals['sitting'],
            'service_types_used' => (int) $serviceTypesUsed,
            'member_total_services' => (int) ($serviceTotals['walk'] + $serviceTotals['daycare'] + $serviceTotals['boarding_total'] + $serviceTotals['drop_in'] + $serviceTotals['sitting']),
            'months_active' => memberBadgeMonthsSinceDate($memberCreatedAt),
            'renewal_count' => (int) valueFromRow($membershipSummary, array('renewal_count'), 0),
            'weekend_services' => (int) $weekendServices,
            'morning_services' => (int) $morningServices,
            'evening_services' => (int) $eveningServices,
        );
    }
}

if (!function_exists('memberBadgeRoadmapConditionMet')) {
    function memberBadgeRoadmapConditionMet(array $config, array $snapshot)
    {
        $metric = (string) valueFromRow($config, array('unlock_metric'), '');
        $operator = (string) valueFromRow($config, array('unlock_operator'), '>=');
        $threshold = (int) valueFromRow($config, array('unlock_threshold'), 0);
        $value = (int) valueFromRow($snapshot, array($metric), 0);

        if ($metric === '') {
            return false;
        }

        if ($operator === '>') {
            return $value > $threshold;
        }
        if ($operator === '=') {
            return $value === $threshold;
        }
        if ($operator === '<=') {
            return $value <= $threshold;
        }
        if ($operator === '<') {
            return $value < $threshold;
        }

        return $value >= $threshold;
    }
}

if (!function_exists('syncRoadmapAutoBadges')) {
    function syncRoadmapAutoBadges(PDO $pdo, $userId, array $snapshot)
    {
        if ((int) $userId <= 0) {
            return;
        }

        foreach (memberBadgeRoadmapCatalogDetailed() as $config) {
            if (!memberBadgeRoadmapConditionMet((array) $config, $snapshot)) {
                continue;
            }

            awardOrUpdateMemberBadge($pdo, array(
                'user_id' => (int) $userId,
                'pet_id' => 0,
                'badge_key' => (string) valueFromRow($config, array('badge_key'), ''),
                'badge_name' => (string) valueFromRow($config, array('badge_name'), 'Badge'),
                'badge_mark' => (string) valueFromRow($config, array('badge_mark'), ''),
                'badge_group' => (string) valueFromRow($config, array('badge_group'), 'service'),
                'badge_family' => (string) valueFromRow($config, array('badge_family'), ''),
                'badge_scope' => (string) valueFromRow($config, array('badge_scope'), 'member'),
                'theme_class' => (string) valueFromRow($config, array('theme_class'), ''),
                'description' => (string) valueFromRow($config, array('description'), ''),
                'reward_title' => (string) valueFromRow($config, array('reward_title'), 'Reward Slot'),
                'reward_note' => (string) valueFromRow($config, array('reward_note'), ''),
                'source_type' => 'roadmap_auto_sync',
                'source_reference' => (string) valueFromRow($config, array('unlock_metric'), '') . ':' . (string) valueFromRow($config, array('unlock_threshold'), 0),
                'is_active' => 1,
                'is_featured' => 1,
            ));
        }
    }
}

if (!function_exists('buildRoadmapBadgeVault')) {
    function buildRoadmapBadgeVault(PDO $pdo, $userId)
    {
        $definitions = memberBadgeRoadmapSectionDefinitions();
        $catalog = memberBadgeRoadmapCatalogDetailed();
        $sections = array();
        $unlockedCount = 0;

        foreach ($definitions as $family => $title) {
            $sections[$family] = array(
                'family' => $family,
                'title' => $title,
                'items' => array(),
                'unlocked_count' => 0,
                'total_count' => 0,
            );
        }

        $activeByKey = array();
        foreach (fetchActiveMemberBadges($pdo, (int) $userId) as $badge) {
            $badgeKey = trim((string) valueFromRow((array) $badge, array('badge_key'), ''));
            if ($badgeKey !== '') {
                $activeByKey[$badgeKey] = (array) $badge;
            }
        }

        foreach ($catalog as $config) {
            $family = (string) valueFromRow($config, array('badge_family'), '');
            if (!isset($sections[$family])) {
                continue;
            }

            $badgeKey = (string) valueFromRow($config, array('badge_key'), '');
            $activeBadge = isset($activeByKey[$badgeKey]) ? (array) $activeByKey[$badgeKey] : array();
            $unlocked = $activeBadge !== array();

            $item = array(
                'badge_key' => $badgeKey,
                'badge_name' => (string) valueFromRow($config, array('badge_name'), 'Badge'),
                'badge_mark' => (string) valueFromRow($config, array('badge_mark'), 'BDG'),
                'theme_class' => (string) valueFromRow($config, array('theme_class'), ''),
                'description' => (string) valueFromRow($config, array('description'), ''),
                'reward_title' => (string) valueFromRow($config, array('reward_title'), 'Reward Slot'),
                'reward_note' => (string) valueFromRow($config, array('reward_note'), ''),
                'unlocked' => $unlocked,
                'status_label' => $unlocked ? 'Unlocked' : 'Locked',
            );

            $sections[$family]['items'][] = $item;
            $sections[$family]['total_count']++;

            if ($unlocked) {
                $sections[$family]['unlocked_count']++;
                $unlockedCount++;
            }
        }

        return array(
            'sections' => array_values($sections),
            'unlocked_count' => $unlockedCount,
        );
    }
}

if (!function_exists('buildRewardTierSnapshot')) {
    function buildRewardTierSnapshot(PDO $pdo, $userId)
    {
        $tiers = array(
            array(
                'key' => 'bronze_collar',
                'name' => 'Bronze Collar',
                'min' => 0,
                'max' => 4,
                'theme_class' => 'reward-tier-bronze',
                'reward_note' => 'Building your first premium milestones and reward access.',
            ),
            array(
                'key' => 'silver_leash',
                'name' => 'Silver Leash',
                'min' => 5,
                'max' => 11,
                'theme_class' => 'reward-tier-silver',
                'reward_note' => 'Routine status unlocked as your badge collection starts to compound.',
            ),
            array(
                'key' => 'gold_paw',
                'name' => 'Gold Paw',
                'min' => 12,
                'max' => 21,
                'theme_class' => 'reward-tier-gold',
                'reward_note' => 'A premium mid-tier reserved for members with a serious badge history.',
            ),
            array(
                'key' => 'platinum_pack',
                'name' => 'Platinum Pack',
                'min' => 22,
                'max' => 34,
                'theme_class' => 'reward-tier-platinum',
                'reward_note' => 'High-value collection status with room for future concierge-style rewards.',
            ),
            array(
                'key' => 'black_tag_circle',
                'name' => 'Black Tag Circle',
                'min' => 35,
                'max' => 999999,
                'theme_class' => 'reward-tier-blacktag',
                'reward_note' => 'The highest visible tier for members with a deep premium badge vault.',
            ),
        );

        $activeBadges = fetchActiveMemberBadges($pdo, (int) $userId);
        $totalUnlocked = count($activeBadges);
        $currentTier = $tiers[0];
        $nextTier = null;

        foreach ($tiers as $index => $tier) {
            if ($totalUnlocked >= (int) $tier['min']) {
                $currentTier = $tier;
                $nextTier = isset($tiers[$index + 1]) ? $tiers[$index + 1] : null;
            }
        }

        if ($nextTier !== null) {
            $badgesToNext = max(0, (int) $nextTier['min'] - $totalUnlocked);
            $span = max(1, (int) $nextTier['min'] - (int) $currentTier['min']);
            $progress = (($totalUnlocked - (int) $currentTier['min']) / $span) * 100;
            $nextMessage = $badgesToNext . ' more badge' . ($badgesToNext === 1 ? '' : 's') . ' to reach ' . $nextTier['name'] . '.';
        } else {
            $badgesToNext = 0;
            $progress = 100;
            $nextMessage = 'Top tier reached.';
        }

        if ($progress < 0) {
            $progress = 0;
        }
        if ($progress > 100) {
            $progress = 100;
        }

        return array(
            'total_unlocked' => $totalUnlocked,
            'current_tier_key' => (string) $currentTier['key'],
            'current_tier_name' => (string) $currentTier['name'],
            'range_label' => (string) $currentTier['min'] . '+' . ((int) $currentTier['max'] < 999999 ? ' to ' . (string) $currentTier['max'] : '') . ' badges',
            'reward_note' => (string) $currentTier['reward_note'],
            'theme_class' => (string) $currentTier['theme_class'],
            'progress_percent' => round($progress, 2),
            'next_tier_name' => $nextTier !== null ? (string) $nextTier['name'] : '',
            'next_tier_message' => $nextMessage,
            'badges_to_next' => $badgesToNext,
        );
    }
}
