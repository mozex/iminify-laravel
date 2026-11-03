<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Mozex\Iminify\Enums\WebhookEventType;
use Mozex\Iminify\Laravel\Events\WebhookReceivedEvent;
use Mozex\Iminify\Laravel\Tests\Fakes\ReadsSession;
use Mozex\Iminify\Laravel\Tests\Fakes\StrictCsrfToken;
use Mozex\Iminify\Laravel\Tests\Fakes\StrictRequestForgery;
use Mozex\Iminify\Testing\Fixtures;
use Mozex\Iminify\Webhooks\WebhookVerifier;

const WEBHOOK_SECRET = 'whsec_test-signing-secret';

beforeEach(function () {
    Config::set('iminify.webhooks.secret', WEBHOOK_SECRET);
    Route::middleware('web')->group(fn () => Route::iminifyWebhooks());
    Event::fake([WebhookReceivedEvent::class]);
});

/**
 * A webhook's body as Iminify sends it.
 */
function webhookBody(string $type = 'image.completed'): string
{
    return (string) json_encode([
        'id' => 'delivery-1',
        'type' => $type,
        'created_at' => '2026-11-03T10:00:00Z',
        'data' => $type === 'ping' ? [] : Fixtures::image(['id' => 'image-7']),
    ]);
}

/**
 * Posts a body to the route the way Iminify does: raw JSON with its signature header.
 */
function sendWebhook(string $body, ?string $signature): TestResponse
{
    return test()->call('POST', '/iminify/webhooks', [], [], [], array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_IMINIFY_SIGNATURE' => $signature,
    ]), $body);
}

describe('a signed webhook', function () {
    it('dispatches the event it read and answers 204, past the web group\'s CSRF check', function () {
        app(Kernel::class)->appendMiddlewareToGroup('web', class_exists(PreventRequestForgery::class) ? StrictRequestForgery::class : StrictCsrfToken::class);
        Route::middleware('web')->post('another', fn (): string => 'reached');
        $body = webhookBody();

        $this->call('POST', '/another')->assertStatus(419);
        sendWebhook($body, (new WebhookVerifier(WEBHOOK_SECRET))->sign($body))->assertNoContent();

        Event::assertDispatched(WebhookReceivedEvent::class, fn (WebhookReceivedEvent $event): bool => $event->event->id === 'delivery-1'
            && $event->event->type === WebhookEventType::ImageCompleted
            && $event->event->image()?->id === 'image-7');
    });

    it('keeps the session, which an app\'s own web middleware may read', function () {
        app(Kernel::class)->appendMiddlewareToGroup('web', ReadsSession::class);
        $body = webhookBody('ping');

        sendWebhook($body, (new WebhookVerifier(WEBHOOK_SECRET))->sign($body))->assertNoContent();
    });

    it('takes a secret written with spaces around it', function () {
        Config::set('iminify.webhooks.secret', ' '.WEBHOOK_SECRET." \n");
        $body = webhookBody('ping');

        sendWebhook($body, (new WebhookVerifier(WEBHOOK_SECRET))->sign($body))->assertNoContent();

        Event::assertDispatched(WebhookReceivedEvent::class, fn (WebhookReceivedEvent $event): bool => $event->event->type === WebhookEventType::Ping);
    });

    it('is registered under the name and address given', function () {
        Route::iminifyWebhooks('hooks/images', 'images.hook');

        expect(route('images.hook', absolute: false))->toBe('/hooks/images')
            ->and(route('iminify.webhooks', absolute: false))->toBe('/iminify/webhooks');
    });
});

describe('a webhook that can\'t be trusted', function () {
    it('is answered 400 and dispatches nothing', function (Closure $signature) {
        $body = webhookBody();

        sendWebhook($body, $signature($body))->assertStatus(400);

        Event::assertNotDispatched(WebhookReceivedEvent::class);
    })->with([
        'no signature' => [fn (string $body): ?string => null],
        'another secret' => [fn (string $body): string => (new WebhookVerifier('whsec_another'))->sign($body)],
        'a stale time' => [fn (string $body): string => (new WebhookVerifier(WEBHOOK_SECRET))->sign($body, time() - 3600)],
        'a changed body' => [fn (string $body): string => (new WebhookVerifier(WEBHOOK_SECRET))->sign($body.' ')],
        'a header that reads as nothing' => [fn (string $body): string => 'garbage'],
    ]);

    it('is answered 400 when the signed body is not an event', function () {
        $body = '{"not": "an event"}';

        sendWebhook($body, (new WebhookVerifier(WEBHOOK_SECRET))->sign($body))->assertStatus(400);

        Event::assertNotDispatched(WebhookReceivedEvent::class);
    });

    it('takes the tolerance from the config', function () {
        Config::set('iminify.webhooks.tolerance', 7200);
        $body = webhookBody();

        sendWebhook($body, (new WebhookVerifier(WEBHOOK_SECRET))->sign($body, time() - 3600))->assertNoContent();
    });
});

describe('an app with no secret', function () {
    it('fails loudly rather than letting a webhook through', function (?string $secret) {
        Config::set('iminify.webhooks.secret', $secret);
        $this->withoutExceptionHandling();
        $body = webhookBody();

        expect(fn () => sendWebhook($body, (new WebhookVerifier(WEBHOOK_SECRET))->sign($body)))->toThrow(RuntimeException::class, 'IMINIFY_WEBHOOK_SECRET');

        Event::assertNotDispatched(WebhookReceivedEvent::class);
    })->with([null, '', '  ']);
});
