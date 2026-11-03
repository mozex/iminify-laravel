<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Mozex\Iminify\Client;
use Mozex\Iminify\Http\ApiResponse;
use Mozex\Iminify\Testing\FakeClient;

/**
 * @method static \Mozex\Iminify\Resources\Images images()
 * @method static \Mozex\Iminify\Resources\Groups groups()
 * @method static \Mozex\Iminify\Resources\Scans scans()
 * @method static \Mozex\Iminify\Resources\Crawls crawls()
 * @method static \Mozex\Iminify\Resources\Monitors monitors()
 * @method static \Mozex\Iminify\Resources\Account account()
 * @method static \Mozex\Iminify\Contracts\TransporterContract transporter()
 * @method static FakeClient addAnswers(array<array-key, array<array-key, mixed>|\Mozex\Iminify\Http\ApiResponse|\Throwable> $answers)
 * @method static list<\Mozex\Iminify\Http\ApiRequest> requests()
 * @method static list<\Mozex\Iminify\Http\ApiRequest> sent(string $method, string $path, ?\Closure $callback = null)
 * @method static void assertSent(string $method, string $path, ?\Closure $callback = null)
 * @method static void assertNotSent(string $method, string $path, ?\Closure $callback = null)
 * @method static void assertSentTimes(string $method, string $path, int $times)
 * @method static void assertNothingSent()
 *
 * @see Client
 * @see FakeClient
 */
class Iminify extends Facade
{
    /**
     * Swaps the app's client for a fake that answers what you queue, in order, and keeps every
     * call to assert on. The optimizer uses it too, and a wait never sleeps; the optimizer's
     * pause after a rate limit goes through `Sleep`, so `Sleep::fake()` skips it.
     *
     * @param  array<array-key, array<array-key, mixed>|ApiResponse|\Throwable>  $answers
     */
    public static function fake(array $answers = []): FakeClient
    {
        $fake = new FakeClient($answers);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
