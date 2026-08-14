<?php

declare(strict_types=1);

/**
 * Gemini request quotas used only as a dated snapshot.
 *
 * Google can raise, lower, or zero these values, or ship a new model, at any
 * time. This file is the override seam: publish it and edit `models` when
 * AI Studio disagrees with the snapshot. Do not treat these numbers as
 * permanent API contracts.
 *
 * - Add a new model: `'gemini-4-flash-lite' => ['rpm' => 60, 'rpd' => 2000]`
 * - Raise/lower an existing row: change `rpm` / `rpd`
 * - Retire a model on the free tier: `['rpm' => 0, 'rpd' => 0]`
 * - Stop using the snapshot for a model: `'gemini-2.5-flash-lite' => null`
 * - Omit `rpm` or `rpd` to leave that dimension unknown (no cap / no daily warn)
 */
return [
    /*
    | Gemini model used for translations.
    |
    | Any model id from ListModels is allowed (free or paid), e.g.
    | gemini-3.5-flash-lite, gemini-3.5-flash, gemini-2.5-pro.
    | CLI --model wins, then this value, then config('gemini.model'),
    | then the package default.
    */
    'model' => env('GEMINI_TRANSLATOR_MODEL', env('GEMINI_MODEL')),

    'quotas' => [
        'as_of' => '2026-08-13',
        'apply_free_tier_caps' => filter_var(env('GEMINI_TRANSLATOR_APPLY_FREE_TIER_CAPS', true), FILTER_VALIDATE_BOOLEAN),
        'models' => [
            // Intersection of ListModels + published free-tier quotas (2026-08-13).
            // gemini-2.5-flash-exp / gemini-3-flash were omitted: not in ListModels.
            'gemini-3.5-flash-lite' => ['rpm' => 15, 'rpd' => 500],
            'gemini-3.1-flash-lite' => ['rpm' => 15, 'rpd' => 500],
            'gemini-2.5-flash-lite' => ['rpm' => 10, 'rpd' => 20],
            'gemini-2.5-flash' => ['rpm' => 5, 'rpd' => 20],
            'gemini-3.5-flash' => ['rpm' => 5, 'rpd' => 20],
            'gemini-3.6-flash' => ['rpm' => 5, 'rpd' => 20],
        ],
    ],

    /*
    | Translation Manager HTTP UI.
    |
    | Enabled by default. Visit /{prefix}.
    | If the host app already has auth routes (login / register / sign-in),
    | guests are sent through that login first. If no auth routes exist,
    | the manager is available without signing in.
    */
    'manager' => [
        'enabled' => filter_var(env('GEMINI_TRANSLATOR_MANAGER', true), FILTER_VALIDATE_BOOLEAN),
        'prefix' => env('GEMINI_TRANSLATOR_MANAGER_PREFIX', 'translations-manager'),
        'middleware' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('GEMINI_TRANSLATOR_MANAGER_MIDDLEWARE', 'web')),
        ))),
    ],
];
