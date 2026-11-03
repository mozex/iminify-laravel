<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\Tests\Fakes;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * Laravel's CSRF check, which lets every request through under unit tests, made to check them
 * as it does in an app, and a subclass as an app's own is.
 */
class StrictCsrfToken extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
