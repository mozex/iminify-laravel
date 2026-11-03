<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\Http;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use LogicException;
use Mozex\Iminify\Laravel\Events\WebhookReceivedEvent;
use Mozex\Iminify\Webhooks\WebhookEvent;
use Symfony\Component\HttpFoundation\Response;

/**
 * The route `Route::iminifyWebhooks()` registers: it dispatches `WebhookReceivedEvent` for the
 * request `VerifyWebhookSignature` let through, and answers 204. Iminify waits only a few
 * seconds for the answer, so a listener with slow work belongs on the queue.
 */
class WebhookController
{
    public function __invoke(Request $request, Dispatcher $events): Response
    {
        $event = $request->attributes->get(WebhookEvent::class);

        if (! $event instanceof WebhookEvent) {
            throw new LogicException('VerifyWebhookSignature checks a webhook before this controller reads it.');
        }

        $events->dispatch(new WebhookReceivedEvent($event));

        return new Response('', 204);
    }
}
