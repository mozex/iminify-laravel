<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\Events;

use Mozex\Iminify\Webhooks\WebhookEvent;

/**
 * A webhook whose signature checked out. `$event->type` says what happened, `$event->image()`
 * and `$event->scan()` read what it's about, and `$event->id` repeats when a failed send is
 * sent again.
 */
class WebhookReceivedEvent
{
    public function __construct(
        public readonly WebhookEvent $event,
    ) {}
}
