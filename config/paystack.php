<?php

return [
    'secret_key' => env('PAYSTACK_SECRET_KEY'),
    'public_key' => env('PAYSTACK_PUBLIC_KEY'),
    'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
    'timeout' => (int) env('PAYSTACK_TIMEOUT', 20),
    'simulate_transfers' => env('PAYSTACK_SIMULATE_TRANSFERS', false),
    'authorization_amount_kobo' => (int) env('PAYSTACK_AUTHORIZATION_AMOUNT_KOBO', 10000),
    'repayment_max_attempts' => (int) env('PAYSTACK_REPAYMENT_MAX_ATTEMPTS', 3),
    'repayment_retry_delay_hours' => (int) env('PAYSTACK_REPAYMENT_RETRY_DELAY_HOURS', 24),
];