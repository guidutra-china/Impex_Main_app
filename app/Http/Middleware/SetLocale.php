<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    protected array $supportedLocales = ['en', 'zh_CN', 'pt_BR'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;

        if ($locale && in_array($locale, $this->supportedLocales)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
