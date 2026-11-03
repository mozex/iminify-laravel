<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Sleep;
use Mozex\Iminify\Client;
use Mozex\Iminify\Laravel\Events\OptimizationSkippedEvent;
use Mozex\Iminify\Spatie\IminifyOptimizer as BaseOptimizer;
use Psr\Log\LoggerInterface;
use Spatie\ImageOptimizer\Image;
use Spatie\ImageOptimizer\OptimizerChain;
use Throwable;

/**
 * The optimizer to list in `config/image-optimizer.php` and `config/media-library.php`, or to
 * put in a chain of your own with `chain()`. It uses the app's client, so the key stays in
 * the config rather than in the options a queued conversion carries, and it takes its
 * `level`, `keep_metadata` and `timeout` from `iminify.optimizer` unless its options set them.
 *
 * A file it can't optimize is left as it was: it dispatches `OptimizationSkippedEvent` and
 * throws, which Spatie's chain logs before it carries on.
 *
 * @phpstan-consistent-constructor
 */
class IminifyOptimizer extends BaseOptimizer
{
    /**
     * A chain holding only this optimizer, for a Media Library conversion's `->optimize()`
     * or spatie/image's.
     *
     * @param  array<string, mixed>  $options  any of `level`, `keep_metadata` and `timeout`
     */
    public static function chain(array $options = []): OptimizerChain
    {
        return (new OptimizerChain)->addOptimizer(new static($options));
    }

    /**
     * An `api_key` option is dropped: the key comes from `iminify.api_key`, and an option would
     * travel in every queued conversion's payload.
     *
     * @param  array<array-key, mixed>  $options
     */
    public function setOptions(array $options = []): static
    {
        unset($options['api_key']);

        return parent::setOptions($options);
    }

    public function handle(Image $image, LoggerInterface $logger): void
    {
        try {
            parent::handle($image, $logger);
        } catch (Throwable $exception) {
            $this->skipped($image, $exception, $logger);

            throw $exception;
        }
    }

    protected function client(): Client
    {
        return Container::getInstance()->make(Client::class);
    }

    protected function timeout(): int
    {
        return $this->withDefaults(fn (): int => parent::timeout());
    }

    /**
     * @return array<string, mixed>
     */
    protected function uploadOptions(): array
    {
        return $this->withDefaults(fn (): array => parent::uploadOptions());
    }

    protected function pause(int $seconds): void
    {
        Sleep::sleep($seconds);
    }

    /**
     * A listener that throws can't take the place of what happened to the file, so its
     * exception is reported to the app and logged to the chain, and the optimizer's own goes on.
     */
    protected function skipped(Image $image, Throwable $exception, LoggerInterface $logger): void
    {
        $container = Container::getInstance();

        try {
            $container->make(Dispatcher::class)->dispatch(new OptimizationSkippedEvent($image->path(), $exception));
        } catch (Throwable $listenerException) {
            $logger->warning("A listener of OptimizationSkippedEvent failed: {$listenerException->getMessage()}");

            if ($container->bound(ExceptionHandler::class)) {
                $container->make(ExceptionHandler::class)->report($listenerException);
            }
        }
    }

    protected static function isSet(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * Runs the parent's reading of the options with the config's values beneath them, and
     * puts the options back afterwards, so nothing from the config is kept in the object a
     * queue serializes. A null or blank value, such as an empty line in `.env`, falls through.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $read
     * @return TResult
     */
    protected function withDefaults(Closure $read): mixed
    {
        $options = $this->options;
        $defaults = Config::get('iminify.optimizer', []);

        $this->options = array_filter($options, static::isSet(...))
            + (is_array($defaults) ? array_filter($defaults, static::isSet(...)) : []);

        try {
            return $read();
        } finally {
            $this->options = $options;
        }
    }
}
