<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\Tests\Fakes;

use Psr\Log\LoggerInterface;
use Spatie\ImageOptimizer\Image;
use Spatie\ImageOptimizer\Optimizers\BaseSelfHandlingOptimizer;

/**
 * An optimizer an app might list, which only counts the files it was handed.
 */
class RecordingOptimizer extends BaseSelfHandlingOptimizer
{
    public static int $handled = 0;

    public function canHandle(Image $image): bool
    {
        return true;
    }

    public function handle(Image $image, LoggerInterface $logger): void
    {
        static::$handled++;
    }
}
