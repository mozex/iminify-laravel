<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel;

use Closure;
use Illuminate\Container\Container;
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
     * A listener that throws can't take the place of what happened to the file.
     */
    protected function skipped(Image $image, Throwable $exception, LoggerInterface $logger): void
    {
        try {
            Container::getInstance()->make(Dispatcher::class)->dispatch(new OptimizationSkippedEvent($image->path(), $exception));
        } catch (Throwable $listenerException) {
            $logger->warning("A listener of OptimizationSkippedEvent failed: {$listenerException->getMessage()}");
        }
    }

    /**
     * Runs the parent's reading of the options with the config's values beneath them, and
     * puts the options back afterwards, so nothing from the config is kept in the object a
     * queue serializes.
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

        $this->options = array_filter($options, fn (mixed $value): bool => $value !== null)
            + (is_array($defaults) ? array_filter($defaults, fn (mixed $value): bool => $value !== null) : []);

        try {
            return $read();
        } finally {
            $this->options = $options;
        }
    }
}
