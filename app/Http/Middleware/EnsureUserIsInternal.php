<?php

namespace App\Http\Middleware;

use App\Domain\Users\Enums\UserType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsInternal
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->type !== UserType::INTERNAL || $user->status !== 'active') {
            return response()->json([
                'message' => 'Forbidden: only active internal users may access the Fair API.',
            ], 403);
        }

        return $next($request);
    }
}
