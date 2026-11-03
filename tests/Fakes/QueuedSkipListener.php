<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\Tests\Fakes;

use Illuminate\Contracts\Queue\ShouldQueue;
use Mozex\Iminify\Laravel\Events\OptimizationSkippedEvent;

/**
 * A queued listener, which the queue serializes with the event it heard.
 */
class QueuedSkipListener implements ShouldQueue
{
    /** @var list<string> */
    public static array $heard = [];

    public function handle(OptimizationSkippedEvent $event): void
    {
        static::$heard[] = $event->reason->getMessage();
    }
}
