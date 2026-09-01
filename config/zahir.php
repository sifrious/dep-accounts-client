<?php

/**
 * Publish with:
 *   php artisan vendor:publish --tag=zahir-config
 *
 * Every value here is deployment configuration. No secret belongs in source
 * control, and the callback and post-logout allowlists are matched exactly —
 * a trailing slash or an extra query parameter is a different URL and will be
 * refused.
 */
return [
    'base_url' => env('ZAHIR_BASE_URL', 'http://localhost:8080'),
    'service_token' => env('ZAHIR_SERVICE_TOKEN'),

    // The product key and entitlement name this application asks Zahir about.
    // These are the application's own; never reuse another product's pair.
    'product' => env('ZAHIR_PRODUCT'),
    'access_entitlement' => env('ZAHIR_ACCESS_ENTITLEMENT', 'access'),

    // How stale a decision may be before a protected request re-asks. This is
    // the whole of the session-invalidation contract: suspension and revocation
    // take effect within this window without Zahir holding any session state.
    'entitlement_decision_max_age_seconds' => (int) env('ZAHIR_ENTITLEMENT_DECISION_MAX_AGE_SECONDS', 30),

    'workos' => [
        'client_id' => env('WORKOS_CLIENT_ID'),
        'client_secret' => env('WORKOS_CLIENT_SECRET'),
        'issuer' => env('WORKOS_ISSUER', 'https://api.workos.com/'),
        'callback_urls' => array_values(array_filter(explode(',', (string) env('WORKOS_CALLBACK_URLS', '')))),
        'post_logout_urls' => array_values(array_filter(explode(',', (string) env('WORKOS_POST_LOGOUT_URLS', '')))),
    ],
];
