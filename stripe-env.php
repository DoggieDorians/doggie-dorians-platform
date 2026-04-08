<?php
declare(strict_types=1);

return [
    'APP_ENV' => 'production',
    'STRIPE_MODE' => 'test',

    'STRIPE_SECRET_KEY_LIVE' => '',
    'STRIPE_PUBLISHABLE_KEY_LIVE' => '',

    'STRIPE_SECRET_KEY_TEST' => 'sk_test_REPLACE_ME',
    'STRIPE_PUBLISHABLE_KEY_TEST' => 'pk_test_REPLACE_ME',

    'STRIPE_WEBHOOK_SECRET' => '',
    'STRIPE_CURRENCY' => 'usd',

    'STRIPE_SUCCESS_URL' => 'https://dorianspetcare.com/payment-success.php?session_id={CHECKOUT_SESSION_ID}',
    'STRIPE_CANCEL_URL' => 'https://dorianspetcare.com/payment-cancel.php',
];