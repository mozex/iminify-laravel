<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\Events;

use Throwable;

/**
 * The optimizer left a file as it was, because of `$reason`: a spent daily allowance, a
 * timeout, a refused key, or an answer it wouldn't write back. The file is still usable.
 */
class OptimizationSkippedEvent
{
    public function __construct(
        public readonly string $path,
        public readonly Throwable $reason,
    ) {}
}
