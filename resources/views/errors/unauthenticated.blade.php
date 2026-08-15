<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title>{{ $pageTitle ?? 'Unauthenticated' }}</title>
</head>
<body class="translation-manager-body">
    @if (!empty($managerStyles))
        <style>{!! $managerStyles !!}</style>
    @endif
    <main class="translation-manager" id="manager-main">
        <div class="manager-shell">
            <section class="manager-panel" aria-labelledby="manager-unauth-title">
                <div class="manager-empty manager-error" role="alert">
                    <h1 class="manager-empty-title" id="manager-unauth-title">{{ $pageTitle ?? 'Unauthenticated' }}</h1>
                    <p class="manager-empty-copy">{{ $pageLede ?? 'You must be signed in to access the translation manager.' }}</p>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
