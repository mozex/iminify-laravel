<?php

declare(strict_types=1);

return [
    /*
     * The API key the client sends, made under Settings > API Tokens on
     * www.iminify.com. Nothing reaches the API without one.
     */
    'api_key' => env('IMINIFY_API_KEY'),

    /*
     * The address the API answers at. Leave it as it is for the live API: the
     * bare domain answers with a redirect, which the client refuses.
     */
    'base_uri' => env('IMINIFY_BASE_URI', 'https://www.iminify.com/api/v1'),

    /*
     * How many seconds one request may take before it fails. A wait for an
     * image is many requests, so this doesn't limit how long a wait takes.
     */
    'timeout' => (int) (filled(env('IMINIFY_TIMEOUT')) ? env('IMINIFY_TIMEOUT') : 30),

    /*
     * What the optimizer sends with each file it takes from Spatie's image
     * optimizer, Media Library or a chain of your own. `level` is none,
     * lossless, smart or ultra, and null leaves it to the API (smart).
     * `keep_metadata` keeps EXIF and the like, stripped by default. `timeout`
     * is how many seconds it waits for one file before leaving it as it was.
     */
    'optimizer' => [
        'level' => env('IMINIFY_LEVEL') ?: null,
        'keep_metadata' => false,
        'timeout' => 120,
    ],

    /*
     * Media Library's responsive images skip the optimizer you configure and
     * always run Spatie's own binaries. Set this to true to run your
     * image_optimizers list on each width instead, with Iminify in place of
     * the binaries it replaces, which counts one image per width.
     */
    'media_library' => [
        'responsive_images' => (bool) env('IMINIFY_RESPONSIVE_IMAGES', false),
    ],

    /*
     * The signing secret of your webhook, shown under Settings > Webhooks
     * when you save its address, with its whsec_ prefix. `tolerance` is how
     * many seconds a request's time may be from your server's clock.
     */
    'webhooks' => [
        'secret' => env('IMINIFY_WEBHOOK_SECRET'),
        'tolerance' => 300,
    ],
];
