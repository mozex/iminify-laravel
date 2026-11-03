<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Mozex\Iminify\Client;
use Mozex\Iminify\Exceptions\DailyLimitReachedException;
use Mozex\Iminify\Http\ApiRequest;
use Mozex\Iminify\Laravel\Events\OptimizationSkippedEvent;
use Mozex\Iminify\Laravel\Facades\Iminify;
use Mozex\Iminify\Laravel\IminifyOptimizer;
use Mozex\Iminify\Testing\Fixtures;
use Psr\Log\NullLogger;
use Spatie\ImageOptimizer\Image;

/**
 * The form fields the optimizer sent with its upload.
 *
 * @return array<string, mixed>
 */
function uploadFields(): array
{
    $upload = Iminify::sent('POST', 'images')[0] ?? null;

    expect($upload)->toBeInstanceOf(ApiRequest::class);

    return $upload->fields;
}

describe('the app\'s client', function () {
    it('uses the client the app made, and needs no key in its options', function () {
        $path = jpeg();
        $before = filesize($path);
        $api = fakeApi();

        (new IminifyOptimizer)->handle(new Image($path), new NullLogger);

        expect(filesize($path))->toBeLessThan($before)
            ->and(array_map(fn (ApiRequest $request): string => "{$request->method} {$request->path}", $api->requests))
            ->toBe(['POST images', 'GET images/image-1', 'GET images/image-1/download', 'DELETE images/image-1']);
    });

    it('refuses to start, leaving the file as it was, when the app has no key', function () {
        Config::set('iminify.api_key', null);
        $path = jpeg();
        $before = (string) file_get_contents($path);

        expect(fn () => (new IminifyOptimizer)->handle(new Image($path), new NullLogger))->toThrow(InvalidArgumentException::class, 'IMINIFY_API_KEY')
            ->and(file_get_contents($path))->toBe($before);
    });
});

describe('its options', function () {
    it('takes the level, the metadata choice and the wait from the config', function () {
        Config::set('iminify.optimizer', ['level' => 'ultra', 'keep_metadata' => true, 'timeout' => 30]);
        Iminify::fake([Fixtures::image(['status' => 'already-optimized']), Fixtures::image(['status' => 'already-optimized']), Fixtures::noContent()]);

        $optimizer = new IminifyOptimizer;
        $optimizer->handle(new Image(jpeg()), new NullLogger);

        expect(uploadFields())->toMatchArray(['level' => 'ultra', 'keep_metadata' => true, 'alt_text' => false, 'ephemeral' => true])
            ->and((fn (): int => $this->timeout())->call($optimizer))->toBe(30)
            ->and($optimizer->options)->toBe([]);
    });

    it('lets its own options win over the config, however it was built', function (Closure $build) {
        Config::set('iminify.optimizer', ['level' => 'ultra', 'keep_metadata' => true, 'timeout' => 30]);
        Iminify::fake([Fixtures::image(['status' => 'already-optimized']), Fixtures::image(['status' => 'already-optimized']), Fixtures::noContent()]);

        $optimizer = $build();
        $optimizer->handle(new Image(jpeg()), new NullLogger);

        expect(uploadFields())->toMatchArray(['level' => 'lossless', 'keep_metadata' => true])
            ->and((fn (): int => $this->timeout())->call($optimizer))->toBe(15);
    })->with([
        'as Media Library builds it' => [fn (): IminifyOptimizer => new IminifyOptimizer(['level' => 'lossless', 'timeout' => 15])],
        'as laravel-image-optimizer builds it' => [fn (): IminifyOptimizer => (new IminifyOptimizer)->setOptions(['level' => 'lossless', 'timeout' => 15])],
    ]);

    it('leaves the level to the API when neither sets one', function () {
        Iminify::fake([Fixtures::image(['status' => 'already-optimized']), Fixtures::image(['status' => 'already-optimized']), Fixtures::noContent()]);

        (new IminifyOptimizer)->handle(new Image(jpeg()), new NullLogger);

        expect(array_key_exists('level', uploadFields()))->toBeFalse();
    });

    it('refuses a wait the config sets out of range', function () {
        Config::set('iminify.optimizer.timeout', 0);
        Iminify::fake();

        (new IminifyOptimizer)->handle(new Image(jpeg()), new NullLogger);
    })->throws(InvalidArgumentException::class, 'timeout');
});

describe('a queue', function () {
    it('carries neither a key nor a client in what it serializes', function () {
        Config::set('iminify.api_key', 'a-secret-key');
        $chain = IminifyOptimizer::chain(['level' => 'smart']);

        app(Client::class);
        $payload = serialize($chain);
        $restored = unserialize($payload)->getOptimizers();

        expect(str_contains($payload, 'a-secret-key'))->toBeFalse()
            ->and(str_contains($payload, 'Mozex\Iminify\Client'))->toBeFalse()
            ->and($restored)->toHaveCount(1)
            ->and($restored[0])->toBeInstanceOf(IminifyOptimizer::class)
            ->and($restored[0]->options)->toBe(['level' => 'smart']);
    });
});

describe('a file it leaves as it was', function () {
    it('says so with an event, and still throws for the chain to log', function () {
        Event::fake([OptimizationSkippedEvent::class]);
        Iminify::fake([Fixtures::refusal('daily_limit_reached', 429)]);
        $path = jpeg();

        expect(fn () => (new IminifyOptimizer)->handle(new Image($path), new NullLogger))->toThrow(DailyLimitReachedException::class);

        Event::assertDispatched(OptimizationSkippedEvent::class, fn (OptimizationSkippedEvent $event): bool => $event->path === $path && $event->reason instanceof DailyLimitReachedException);
    });

    it('keeps the reason when a listener of the event throws', function () {
        Event::listen(OptimizationSkippedEvent::class, function (): void {
            throw new RuntimeException('A broken listener');
        });
        Iminify::fake([Fixtures::refusal('daily_limit_reached', 429)]);

        (new IminifyOptimizer)->handle(new Image(jpeg()), new NullLogger);
    })->throws(DailyLimitReachedException::class);

    it('sends no event for a file Iminify found nothing to save in', function () {
        Event::fake([OptimizationSkippedEvent::class]);
        Iminify::fake([Fixtures::image(['status' => 'already-optimized']), Fixtures::image(['status' => 'already-optimized']), Fixtures::noContent()]);

        (new IminifyOptimizer)->handle(new Image(jpeg()), new NullLogger);

        Event::assertNotDispatched(OptimizationSkippedEvent::class);
    });
});

describe('a rate limit', function () {
    it('is waited out through Laravel\'s Sleep, so a test can fake it', function () {
        Sleep::fake();
        Iminify::fake([
            Fixtures::refusal('rate_limited', 429, retryAfter: 7),
            Fixtures::image(['status' => 'already-optimized']),
            Fixtures::image(['status' => 'already-optimized']),
            Fixtures::noContent(),
        ]);

        (new IminifyOptimizer)->handle(new Image(jpeg()), new NullLogger);

        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(7)->seconds()]);
        Iminify::assertSentTimes('POST', 'images', 2);
    });
});

describe('a chain', function () {
    it('holds only this optimizer, with the options given', function () {
        $optimizers = IminifyOptimizer::chain(['level' => 'ultra'])->getOptimizers();

        expect($optimizers)->toHaveCount(1)
            ->and($optimizers[0])->toBeInstanceOf(IminifyOptimizer::class)
            ->and($optimizers[0]->options)->toBe(['level' => 'ultra']);
    });
});
