<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Http\Support;

use Illuminate\Support\Facades\Route;

final class ManagerAuthGate
{
    public static function isRequired(): bool
    {
        return self::loginUrl() !== null;
    }

    public static function loginUrl(): ?string
    {
        if (Route::has('login')) {
            return route('login');
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            if (str_starts_with($name, 'gemini-translator.')) {
                continue;
            }

            if ($name !== '' && preg_match('/(?:^|\.)(?:login|register|signin|sign-in)$/i', $name) === 1) {
                return url('/' . ltrim($route->uri(), '/'));
            }

            if (preg_match('#(?:^|/)(?:login|register|sign-in|signin|sign-up|signup)(?:/|$)#i', $route->uri()) === 1) {
                return url('/' . ltrim($route->uri(), '/'));
            }
        }

        return null;
    }
}
