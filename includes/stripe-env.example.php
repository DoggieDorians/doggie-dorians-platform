<?php
declare(strict_types=1);

return [
    'APP_ENV' => 'production',
    'STRIPE_MODE' => 'test',

    'PUBLIC_BASE_URL' => 'https://dorianspetcare.com',
    'STRIPE_CURRENCY' => 'usd',

    'STRIPE_SECRET_KEY_TEST' => 'sk_test_REPLACE_ME',
    'STRIPE_PUBLISHABLE_KEY_TEST' => 'pk_test_REPLACE_ME',
    'STRIPE_WEBHOOK_SECRET' => '',

    'STRIPE_SECRET_KEY_LIVE' => '',
    'STRIPE_PUBLISHABLE_KEY_LIVE' => '',

    'STRIPE_SUCCESS_URL' => 'https://dorianspetcare.com/payment-success.php?session_id={CHECKOUT_SESSION_ID}',
    'STRIPE_CANCEL_URL' => 'https://dorianspetcare.com/payment-cancel.php',

    'STRIPE_PRICE_ID_FOUNDER_WALK_TEST' => 'price_REPLACE_ME',
    'STRIPE_PRICE_ID_FOUNDER_CARE_TEST' => 'price_REPLACE_ME',
    'STRIPE_PRICE_ID_FOUNDER_ELITE_TEST' => 'price_REPLACE_ME',

    'STRIPE_PRICE_ID_FOUNDER_WALK_LIVE' => '',
    'STRIPE_PRICE_ID_FOUNDER_CARE_LIVE' => '',
    'STRIPE_PRICE_ID_FOUNDER_ELITE_LIVE' => '',
];
