<?php

declare(strict_types=1);

return [
    /*
    |---------------------------------------------------------------------
    | Network timeouts
    |---------------------------------------------------------------------
    | Connection and total-request timeouts are separate: a hanging connection
    | should drop quickly, while a slow but live response should be read to the
    | end.
    */
    'timeout' => (int) env('SCRAPING_TIMEOUT', 30),
    'connect_timeout' => (int) env('SCRAPING_CONNECT_TIMEOUT', 10),

    /*
    |---------------------------------------------------------------------
    | Pause between requests
    |---------------------------------------------------------------------
    | The delay is picked at random from a range. An even interval is itself a
    | sign of automation, so the jitter here is not a luxury.
    */
    'delay' => [
        'min_ms' => (int) env('SCRAPING_DELAY_MIN_MS', 400),
        'max_ms' => (int) env('SCRAPING_DELAY_MAX_MS', 1200),
    ],

    /*
    |---------------------------------------------------------------------
    | Proxies
    |---------------------------------------------------------------------
    | Comma-separated list. A blocked address is taken out of rotation for a
    | cooldown so that repeated requests do not extend the block.
    */
    'proxies' => array_values(array_filter(
        explode(',', (string) env('SCRAPING_PROXIES', '')),
    )),
    'proxy_cooldown' => (int) env('SCRAPING_PROXY_COOLDOWN', 900),

    /*
    |---------------------------------------------------------------------
    | Yandex.Maps
    |---------------------------------------------------------------------
    */
    'yandex' => [
        // Listing order. by_time gives a stable sequence between runs, unlike
        // relevance, which can reshuffle reviews.
        'ranking' => env('SCRAPING_YANDEX_RANKING', 'by_time'),

        // The source-side output depth limit: requests with offset >= 600 are
        // consistently rejected. Kept in config because this is observed
        // behaviour of a third-party service, not a constant of ours.
        'max_reviews' => (int) env('SCRAPING_YANDEX_MAX_REVIEWS', 600),
    ],

    /*
    |---------------------------------------------------------------------
    | Headless browser (fallback strategy)
    |---------------------------------------------------------------------
    | Disabled by default, and not present in the production image: Chromium
    | does not fit the free plan's memory. Install it locally with
    | `npm install -D playwright && npx playwright install chromium`; without it
    | isAvailable() returns false and the strategy drops out of the chain.
    |
    | Measured against a live card: 600 reviews in 45s, versus 16s for the
    | primary path, returning identical records down to the review ids.
    */
    'headless' => [
        'enabled' => (bool) env('SCRAPING_HEADLESS_ENABLED', false),
        'node_binary' => env('SCRAPING_NODE_BINARY', 'node'),
        'script' => base_path('scripts/scrape-yandex.mjs'),
        'timeout' => (int) env('SCRAPING_HEADLESS_TIMEOUT', 300),
    ],
];
