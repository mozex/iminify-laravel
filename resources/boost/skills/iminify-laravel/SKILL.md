---
name: iminify-laravel
description: Compress, convert and resize images through the Iminify API in a Laravel app with mozex/iminify-laravel - the Iminify facade, the IminifyOptimizer for Spatie's Media Library and laravel-image-optimizer, responsive images through Iminify, the Route::iminifyWebhooks() route with WebhookReceivedEvent, OptimizationSkippedEvent, and Iminify::fake() in tests. Use when the user wants Media Library conversions or uploads optimized through Iminify, wants to drop jpegoptim, pngquant or cwebp from a server, calls the Iminify API from Laravel code, receives Iminify webhooks, tests code that calls Iminify, or asks why images aren't getting smaller after adding the optimizer.
---

# Iminify for Laravel

This project has `mozex/iminify-laravel` installed. It puts the client from `mozex/iminify-php` in the container (`Mozex\Iminify\Client`, also the `Iminify` facade), made from `config/iminify.php`, and plugs Iminify into Spatie's image optimizer chain. The API key comes from `IMINIFY_API_KEY`.

## When to use this skill

- Optimizing Media Library conversions or responsive images through Iminify.
- Listing the optimizer in `config/image-optimizer.php` for laravel-image-optimizer or its `OptimizeImages` middleware.
- Calling the API (images, upload groups, page scans, site crawls, weekly checks, the account) from a controller, job or command.
- Receiving Iminify's webhooks, or testing code that calls Iminify.

## Calling the API

```php
use Mozex\Iminify\Laravel\Facades\Iminify;

$image = Iminify::images()->upload(storage_path('app/photo.jpg'), ['format' => 'webp']);
$image = Iminify::images()->wait($image);

if ($image->hasResult()) {
    Iminify::images()->download($image)->saveTo(storage_path('app/photo.webp'));
}
```

Inject `Mozex\Iminify\Client` instead of the facade where the code already takes dependencies. Each refusal throws its own exception from `Mozex\Iminify\Exceptions\` (`DailyLimitReachedException`, `RateLimitedException` with `$retryAfter`, `ValidationException` with `$errors`), all under `ApiException` with the API's `$errorCode`. `IMINIFY_TIMEOUT` (30 seconds) limits one request, not a whole wait.

## Media Library

Replace jpegoptim, pngquant, optipng, gifsicle and cwebp in `config/media-library.php`, and keep at least one of Spatie's own optimizers:

```php
'image_optimizers' => [
    Mozex\Iminify\Laravel\IminifyOptimizer::class => [],
    Spatie\ImageOptimizer\Optimizers\Svgo::class => ['--disable=cleanupIDs'],
    Spatie\ImageOptimizer\Optimizers\Avifenc::class => ['-a cq-level=23', '-j all', '--min 0', '--max 63', '--minalpha 0', '--maxalpha 63', '-a end-usage=q', '-a tune=ssim'],
],
```

Svgo and avifenc stay for the SVG and AVIF files Iminify doesn't read. A list with only `IminifyOptimizer` in it silently gets Spatie's default binaries: spatie/image-optimizer's factory, which Media Library calls, recognizes only Spatie's seven built-in classes. That's the first thing to check when images aren't getting smaller.

- For some conversions only, leave the config alone and call `->optimize(IminifyOptimizer::chain())` on those conversions.
- Responsive images ignore the config and always run the binaries; `IMINIFY_RESPONSIVE_IMAGES=true` runs the `image_optimizers` list on each width, with Iminify in place of jpegoptim, pngquant, optipng, gifsicle and cwebp when the list lacks it.
- The original upload is never optimized, only conversions.
- Queued conversions are fine: the optimizer carries only its options, never the key; an `api_key` option is dropped.
- Each optimized file counts one image against the account's daily images: one per conversion, plus one per responsive width when that's on. `php artisan media-library:regenerate --only=<conversion>` optimizes existing media again and counts again.
- Needs Media Library 11.23 or newer.

## laravel-image-optimizer

```php
// config/image-optimizer.php
'optimizers' => [
    Mozex\Iminify\Laravel\IminifyOptimizer::class => [],
    Spatie\ImageOptimizer\Optimizers\Svgo::class => ['--disable=cleanupIDs'],
],
```

`ImageOptimizer::optimize($path)` and the `OptimizeImages` middleware then go through Iminify. The middleware waits for each file inside the request (a few seconds per image), so prefer a queued job for busy forms.

## The optimizer's behavior

- Takes JPEG, PNG, WebP and GIF by the file's contents; SVG and AVIF stay with the rest of the chain.
- Sends no format (the file keeps its own), no alt text request, and `ephemeral`, then deletes the image from the account when it's done with the file, whether or not it wrote anything back.
- Writes back only a smaller copy of the same type, and only when the file didn't change meanwhile.
- `iminify.optimizer` sets `level` (`none`, `lossless`, `smart`, `ultra`; `IMINIFY_LEVEL`), `keep_metadata` and `timeout` (seconds per file, 120). Options in a config list override it: `IminifyOptimizer::class => ['level' => 'ultra']`.
- On any failure (a spent allowance, a timeout, a refused key) it leaves the file as it was, dispatches `Mozex\Iminify\Laravel\Events\OptimizationSkippedEvent` (`$path`, `$reason`) and throws, which Spatie's chain logs to its logger (off by default) and moves past. A listener's own exception is reported to the app's exception handler. A queued listener gets `$reason` as an `IminifyException` naming the original's class and message.

## Webhooks

```php
// routes/web.php
Route::iminifyWebhooks(); // POST /iminify/webhooks, named iminify.webhooks, without the CSRF check
```

Set `IMINIFY_WEBHOOK_SECRET` to the `whsec_` secret from Settings, Webhooks. The route checks the signature against the raw body, answers 400 to anything else, and dispatches `Mozex\Iminify\Laravel\Events\WebhookReceivedEvent`:

```php
Event::listen(function (WebhookReceivedEvent $received) {
    if ($received->event->type === WebhookEventType::ImageCompleted) {
        $image = $received->event->image();
    }
});
```

`$received->event->id` repeats when a failed send is sent again. Iminify counts a slow answer as a failed send (its webhooks guide states the limit), so slow listeners implement `ShouldQueue`.

## Tests

```php
use Mozex\Iminify\Laravel\Facades\Iminify;
use Mozex\Iminify\Testing\Fixtures;

Iminify::fake([Fixtures::image(['id' => 'abc']), Fixtures::refusal('daily_limit_reached', 429)]);

// ... code under test

Iminify::assertSent('GET', 'images/abc');
Iminify::assertNotSent('DELETE', 'images/*');
```

The fake answers in order through the real resources, so refusals throw the real exceptions; the optimizer uses it too. A call with no answer queued throws a `LogicException` naming it. Rate-limit waits in the optimizer go through Laravel's `Sleep`, so `Sleep::fake()` skips them. Sign a webhook body for a route test with `(new Mozex\Iminify\Webhooks\WebhookVerifier($secret))->sign($body)` and send it in the `Iminify-Signature` header.
