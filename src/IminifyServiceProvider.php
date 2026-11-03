<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Mozex\Iminify\Client;
use Mozex\Iminify\Iminify;
use Mozex\Iminify\Laravel\Http\VerifyWebhookSignature;
use Mozex\Iminify\Laravel\Http\WebhookController;
use Mozex\Iminify\Laravel\MediaLibrary\ResponsiveImageGenerator;
use Mozex\Iminify\Support\ClientIdentifier;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator as MediaLibraryGenerator;
use Throwable;

/**
 * Not deferred: the install command, the webhook route macro and the responsive images
 * binding have to be there before anything asks for the client, which is made only when
 * something does.
 */
class IminifyServiceProvider extends PackageServiceProvider
{
    protected string $repository = 'https://github.com/mozex/iminify-laravel';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('iminify')
            ->hasConfigFile()
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->endWith(fn (InstallCommand $command) => $this->askToStar($command));
            });
    }

    public function packageRegistered(): void
    {
        $this->mergeNestedConfig();

        $this->app->singleton(Client::class, fn (): Client => $this->makeClient());
        $this->app->alias(Client::class, 'iminify');
    }

    public function packageBooted(): void
    {
        // Iminify sends no CSRF token and keeps no cookies, so the route goes without the check,
        // under every class a Laravel version gives it, and without a session, which would be a
        // new one for every delivery. The router also leaves out a subclass of one left out.
        Router::macro('iminifyWebhooks', function (string $uri = 'iminify/webhooks', string $name = 'iminify.webhooks'): Route {
            /** @var Router $this */
            return $this->post($uri, [WebhookController::class, '__invoke'])
                ->middleware(VerifyWebhookSignature::class)
                ->withoutMiddleware([
                    'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
                    'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
                    'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
                    'Illuminate\Session\Middleware\StartSession',
                    'Illuminate\View\Middleware\ShareErrorsFromSession',
                    'Illuminate\Cookie\Middleware\EncryptCookies',
                    'Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse',
                ])
                ->name($name);
        });

        if (Config::get('iminify.media_library.responsive_images') === true && class_exists(MediaLibraryGenerator::class)) {
            $this->app->bind(MediaLibraryGenerator::class, ResponsiveImageGenerator::class);
        }
    }

    /**
     * The client takes its timeout from the HTTP client it's given, so the provider makes one.
     * Guzzle never follows a redirect through PSR-18, which keeps a POST from arriving as a GET.
     */
    protected function makeClient(): Client
    {
        $apiKey = Config::get('iminify.api_key');
        $baseUri = Config::get('iminify.base_uri');
        $timeout = Config::get('iminify.timeout');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new InvalidArgumentException('Set IMINIFY_API_KEY (iminify.api_key) to an API key made under Settings > API Tokens at https://www.iminify.com.');
        }

        if (! is_numeric($timeout) || (int) $timeout < 1) {
            throw new InvalidArgumentException('iminify.timeout is the seconds one request may take, at least 1.');
        }

        return Iminify::factory()
            ->withApiKey($apiKey)
            ->withBaseUri(is_string($baseUri) && $baseUri !== '' ? $baseUri : Iminify::BASE_URI)
            ->withHttpClient(new GuzzleClient(['timeout' => (int) $timeout]))
            ->withClientIdentifier(ClientIdentifier::for('laravel', 'mozex/iminify-laravel'))
            ->withSleep(static function (float $seconds): void {
                Sleep::usleep((int) round($seconds * 1_000_000));
            })
            ->make();
    }

    /**
     * Laravel merges a published config one level deep, so a published `optimizer` group would
     * lose a key added to it later. This fills each group in from the defaults.
     */
    protected function mergeNestedConfig(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        /** @var Repository $config */
        $config = $this->app->make('config');

        /** @var array<string, mixed> $defaults */
        $defaults = require __DIR__.'/../config/iminify.php';
        $published = $config->get('iminify', []);

        $config->set('iminify', array_replace_recursive($defaults, is_array($published) ? $published : []));
    }

    /**
     * A person gets the question, defaulting to yes. A run nobody can answer
     * (--no-interaction, or no terminal on stdin, as with CI and AI agents)
     * takes that default without asking, so it gets a note explaining the
     * browser tab instead.
     */
    protected function askToStar(InstallCommand $command): void
    {
        if (! $this->isInteractive($command)) {
            $command->info('If iminify-laravel saves you time, please consider starring it on GitHub: '.$this->repository);

            $this->openInBrowser();

            return;
        }

        if (! $command->confirm('Would you like to show some love by starring iminify-laravel on GitHub?', true)) {
            return;
        }

        if ($this->openInBrowser()) {
            return;
        }

        $command->info("You'll find iminify-laravel at ".$this->repository);
    }

    /**
     * Laravel's own rule for prompts (stdin must be a terminal, except under
     * unit tests, where the console output is faked), except that
     * --no-interaction always wins, which keeps that path testable.
     */
    protected function isInteractive(InstallCommand $command): bool
    {
        if ($command->option('no-interaction') === true) {
            return false;
        }

        if ($this->app->runningUnitTests()) {
            return true;
        }

        return defined('STDIN') && stream_isatty(STDIN);
    }

    /**
     * Best effort: any failure returns false. On Linux the opener runs in the
     * background, because xdg-open without a detected desktop runs the browser
     * in the foreground and would hold the command until the browser closes.
     */
    protected function openInBrowser(): bool
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $this->repository],
            'Windows' => ['cmd', '/c', 'start', '', $this->repository],
            default => ['sh', '-c', 'command -v xdg-open > /dev/null && (xdg-open "$1" > /dev/null 2>&1 &)', 'sh', $this->repository],
        };

        try {
            return Process::run($command)->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
