<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Mozex\Iminify\Exceptions\InvalidWebhookSignatureException;
use Mozex\Iminify\Exceptions\UnexpectedResponseException;
use Mozex\Iminify\Webhooks\WebhookEvent;
use Mozex\Iminify\Webhooks\WebhookVerifier;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a webhook through only when its `Iminify-Signature` matches the raw body under
 * `iminify.webhooks.secret`, and leaves the event it read on the request for the controller.
 * Anything else is answered 400, which Iminify counts as a failed send.
 */
class VerifyWebhookSignature
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $event = $this->verifier()->verify($request->getContent(), $request->headers->get(WebhookVerifier::HEADER));
        } catch (InvalidWebhookSignatureException|UnexpectedResponseException) {
            return new Response('Invalid webhook signature.', 400);
        }

        $request->attributes->set(WebhookEvent::class, $event);

        return $next($request);
    }

    protected function verifier(): WebhookVerifier
    {
        $secret = Config::get('iminify.webhooks.secret');
        $tolerance = Config::get('iminify.webhooks.tolerance', 300);

        if (! is_string($secret) || trim($secret) === '') {
            throw new RuntimeException('Set IMINIFY_WEBHOOK_SECRET (iminify.webhooks.secret) to the signing secret shown under Settings > Webhooks to receive Iminify webhooks.');
        }

        return new WebhookVerifier(trim($secret), is_numeric($tolerance) ? (int) $tolerance : 300);
    }
}
