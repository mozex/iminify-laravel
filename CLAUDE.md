# iminify-laravel

The Laravel wrapper for the Iminify image optimization API. It builds on `mozex/iminify-php` (the client, its resources, `FakeClient`, the webhook verifier and the plain-PHP Spatie optimizer) and adds what a Laravel app needs: the client in the container from `config/iminify.php`, an `Iminify` facade with `fake()`, an optimizer for laravel-image-optimizer and Media Library that takes its key from the config, Media Library's responsive images through Iminify, and a webhook route with its signature middleware and event.

## Commands

```bash
composer test                  # lint + static analysis + type coverage + tests, the gate before every commit
composer lint                  # fix code style (Pint)
composer test:lint             # check code style without fixing
composer test:types            # PHPStan
composer test:type-coverage    # Pest type coverage (minimum 100%)
composer test:unit             # Pest

vendor/bin/pest tests/SomeTest.php          # one file
vendor/bin/pest --filter="test name here"   # one test
```

## Architecture

- `IminifyServiceProvider` (spatie/laravel-package-tools): `config/iminify.php`, the `iminify:install` command, `Mozex\Iminify\Client` as a lazy singleton (also `iminify`), the `Route::iminifyWebhooks()` macro, and the responsive images binding when `iminify.media_library.responsive_images` is on.
- `Facades\Iminify`: the client, and `fake()` swapping in `Mozex\Iminify\Testing\FakeClient`.
- `IminifyOptimizer` extends the PHP client's `Spatie\IminifyOptimizer`: `client()` from the container, `timeout()` and `uploadOptions()` with `iminify.optimizer` beneath its own options, `pause()` through `Sleep`, and `OptimizationSkippedEvent` before it rethrows. `chain()` is an `OptimizerChain` holding only it.
- `MediaLibrary\ResponsiveImageGenerator`: Media Library's, with `generateResponsiveImage()` copied and its bare `optimize()` given the chain `media-library.image_optimizers` builds; when that chain has no `IminifyOptimizer`, it takes the place of jpegoptim, pngquant, optipng, gifsicle and cwebp there (with the list's options for it, if any), so no width is compressed twice.
- `Http\VerifyWebhookSignature` checks the raw body against `iminify.webhooks.secret` and puts the `WebhookEvent` on the request; `Http\WebhookController` dispatches `Events\WebhookReceivedEvent` and answers 204.

## Key design decisions

- **The provider isn't deferred.** The install command, the route macro and the responsive images binding have to exist before anything asks for the client, and the client itself is only made when something does.
- **The provider makes a Guzzle client with the config's `timeout`** and hands it to the factory. The PHP client's `withTimeout()` reaches only a client discovery found, and Guzzle never follows a redirect through PSR-18. Guzzle is in `require` because `src/` names it; every Laravel app has it already.
- **The optimizer keeps nothing from the config in its properties.** Media Library queues the chain inside the conversion, so `withDefaults()` lays the config under the options for one read and puts them back; the key comes from the container's client when the job runs, never from a payload, and `setOptions()` drops an `api_key` option for the same reason. A null or blank option (an empty line in `.env`) falls through to the config, and the config reads a blank env value as unset.
- **spatie/image-optimizer's `OptimizerChainFactory`, which Media Library calls, ignores a list without one of Spatie's seven built-in classes** and runs its defaults. It's a static call with no binding to replace, so the docs say to keep svgo and avifenc in the list, and a test pins the trap.
- **The responsive images generator copies Media Library's method.** The bare `optimize()` sits inside `generateResponsiveImage()` with nothing to hook, so the override repeats its body. A Media Library release that changes that method needs the copy brought along; the tests run it through `GenerateResponsiveImagesJob` at the lowest and newest versions.
- **The webhook route leaves out every CSRF class a Laravel version has** (`PreventRequestForgery` in Laravel 13's web group, `ValidateCsrfToken` in 12's, `VerifyCsrfToken` in an app upgraded from 10), named as strings, since they live in `illuminate/foundation`. The router also leaves out their subclasses. The session stays: an app's own web middleware may call `$request->session()`, which throws without one, and every delivery would then fail.
- **A listener of `OptimizationSkippedEvent` that throws is reported to the app's exception handler and logged to the chain's logger**, so it can't replace the exception that left the file alone. The event serializes its `$reason` as an `IminifyException` holding the original's class and message, because an exception's trace can hold a PSR-7 stream that a queued listener's payload can't carry.
- **No validation of API options here**: they pass through to the client as sent, as the client does.

## Extension points

- Rebind `Mozex\Iminify\Client` in an app's provider for another HTTP client, proxy or headers; the facade and the optimizer follow it.
- Extend `IminifyOptimizer` and list the subclass: `client()`, `uploadOptions()`, `timeout()` and `pause()` are protected.
- Listen for `OptimizationSkippedEvent` and `WebhookReceivedEvent`.

## Testing

- `tests/TestCase.php` sets an app key (the web group's cookies need one), an API key, a local `media` disk under the system's temporary folder, GD for Media Library and conversions off the queue, and creates `posts` and Media Library's `media` table from its own migration stub. `testbench.yaml` loads Media Library's and laravel-image-optimizer's providers.
- `fakeApi()` (in `tests/Pest.php`) binds a client on `tests/Fakes/FakeIminifyApi`, which answers as the API would for a file whose size the test can't know: it keeps each upload and returns it as a low-quality JPEG. `Iminify::fake()` is for answers a test queues itself.
- `tests/Fixtures/Post` is the Media Library model, with static flags for a queued conversion and a conversion's own chain.
- `tests/Fakes/StrictRequestForgery` (Laravel 13) and `StrictCsrfToken` (Laravel 12) are the CSRF check each version's web group lists, with its unit-test bypass turned off, so the webhook test sees a 419 on a plain route and proves the macro leaves that version's check out. `RecordingOptimizer` stands in for an optimizer an app lists, to prove the responsive chain is built from the config.
- `jpeg()` writes a real JPEG and the `afterEach` on the `uses()` chain removes it and the media disk.
- On Windows, a file `UploadedFile::fake()` makes stays open, so nothing can replace it: the middleware test uses a real `UploadedFile` over a file it wrote.

## Development notes

- `CHANGELOG.md` is generated from GitHub releases by the changelog workflow. Never edit it by hand.
- The PHP floor is 8.2: no typed class constants (`public const string X`) and no `new Foo()->bar()` without wrapping parentheses. PHPStan's `type_coverage.constant` is 0 for that reason.
- The package depends on split `illuminate/*` components, not `laravel/framework`, so `src/` uses facades and the container instead of foundation helpers (`config()`, `base_path()`, `now()`). Every `Illuminate\*` class or facade `src/` uses needs its component in `require`; testbench installs the whole framework, so a green suite doesn't prove it.
- `mozex/iminify-php` comes from its GitHub repository through a `vcs` entry in `repositories` and a `dev-main` constraint until it's on Packagist; then the entry goes and the constraint becomes its first release's.
- `spatie/image-optimizer` is in `require-dev` and `conflict` (below 1.10.0, which has no self-handling optimizers), and Media Library and laravel-image-optimizer in `require-dev` and `suggest`: an app brings the ones it uses. `MediaLibrary\ResponsiveImageGenerator` is loaded only when its config is on and Media Library is installed.
- A change to the README is checked against the Boost skill and this file, and the reverse.
- The README is the docs: the docs site, `https://www.iminify.com/docs/laravel`, reads it at each release and refuses the whole release, keeping the page it had, when it breaks the site's check: one `#` heading (the title), no image, code fenced in a language the site's highlighter knows (`php`, `bash`, `dotenv` and the like), and every link to a docs page or heading on the site resolving (`https://www.iminify.com/docs/...` links, and links to the PHP client's repository, which the site turns into its docs). So a README change is tried with the site's dry run against `main` before the release is tagged.
