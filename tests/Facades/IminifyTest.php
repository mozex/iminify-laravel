<?php

declare(strict_types=1);

use Mozex\Iminify\Client;
use Mozex\Iminify\Exceptions\DailyLimitReachedException;
use Mozex\Iminify\Http\ApiRequest;
use Mozex\Iminify\Laravel\Facades\Iminify;
use Mozex\Iminify\Testing\FakeClient;
use Mozex\Iminify\Testing\Fixtures;

it('swaps in a fake that answers what is queued, for the app\'s client too', function () {
    $fake = Iminify::fake([Fixtures::image(['id' => 'abc']), Fixtures::refusal('daily_limit_reached', 429)]);

    $image = app(Client::class)->images()->get('abc');

    expect($fake)->toBeInstanceOf(FakeClient::class)
        ->and(app(Client::class))->toBe($fake)
        ->and($image->id)->toBe('abc')
        ->and(fn () => Iminify::account()->get())->toThrow(DailyLimitReachedException::class);

    Iminify::assertSent('GET', 'images/abc');
    Iminify::assertSent('GET', 'account', fn (ApiRequest $request): bool => $request->query === []);
    Iminify::assertSentTimes('GET', 'images/*', 1);
    Iminify::assertNotSent('DELETE', 'images/abc');
});

it('starts a fake with nothing sent', function () {
    Iminify::fake();

    Iminify::assertNothingSent();
});
