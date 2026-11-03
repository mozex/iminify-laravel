<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\Tests\Fakes;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web middleware an app might add, such as one taking the locale from the session, which
 * throws when the request has none.
 */
class ReadsSession
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale((string) $request->session()->get('locale', 'en'));

        return $next($request);
    }
}
