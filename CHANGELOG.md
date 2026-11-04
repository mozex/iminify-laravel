# Changelog

All notable changes to `iminify-laravel` will be documented in this file.

## 1.0.0 - 2026-11-04

### What's Changed

The first release of [Iminify](https://www.iminify.com) for Laravel, built on the [PHP client](https://github.com/mozex/iminify-php) 1.0.

* The client in your app's container, set up from `config/iminify.php` and `IMINIFY_API_KEY`, behind an `Iminify` facade; `php artisan iminify:install` publishes the config
* `IminifyOptimizer` for Spatie's Media Library and laravel-image-optimizer, in place of jpegoptim, pngquant, optipng, gifsicle and cwebp, so conversions and uploads come out smaller with none of them on the server
* Media Library's responsive images through Iminify too, behind `IMINIFY_RESPONSIVE_IMAGES` (off by default, since each width counts as an image)
* A file Iminify can't optimize stays as it was, and `OptimizationSkippedEvent` says why
* `Route::iminifyWebhooks()` registers a webhook route that checks each signature against `IMINIFY_WEBHOOK_SECRET` and dispatches `WebhookReceivedEvent`
* `Iminify::fake()` for your tests: it answers what you queue, records every call, and the optimizer uses it too
* A Laravel Boost skill for AI coding assistants
* Runs on PHP 8.2 or newer with Laravel 12 or 13

The docs are at https://www.iminify.com/docs/laravel.

**Full Changelog**: https://github.com/mozex/iminify-laravel/commits/1.0.0
