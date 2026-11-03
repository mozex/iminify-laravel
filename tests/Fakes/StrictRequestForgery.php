<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\Tests\Fakes;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/**
 * Laravel 13's request forgery check, the one its web group lists, with its unit-test bypass
 * turned off. Loaded only where that class exists.
 */
class StrictRequestForgery extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
