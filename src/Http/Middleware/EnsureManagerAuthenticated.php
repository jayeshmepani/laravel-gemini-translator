<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Jayesh\LaravelGeminiTranslator\Http\Support\ManagerAuthGate;
use Symfony\Component\HttpFoundation\Response;

final class EnsureManagerAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() || ! ManagerAuthGate::isRequired()) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'error' => 'Unauthenticated.',
                'message' => 'You must be signed in to access the translation manager.',
            ], 401);
        }

        $loginUrl = ManagerAuthGate::loginUrl();
        if (is_string($loginUrl) && $loginUrl !== '') {
            return redirect()->guest($loginUrl);
        }

        return response()->view('gemini-translator::errors.unauthenticated', [
            'pageTitle' => __('Unauthenticated'),
            'pageLede' => __('You must be signed in to access the translation manager.'),
        ], 401);
    }
}
