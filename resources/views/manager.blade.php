<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#f1f5f9" data-theme-color-light>
    <meta name="theme-color" content="#050505" media="(prefers-color-scheme: dark)" data-theme-color-dark>
    <meta name="description" content="{{ $pageLede }}">
    <title>{{ $documentTitle }}</title>
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('gemini-translator-theme');
                var theme = stored === 'dark' || stored === 'light'
                    ? stored
                    : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', theme);
                document.documentElement.style.colorScheme = theme;
            } catch (error) {}
        }());
    </script>
</head>
<body class="translation-manager-body">
    <a class="manager-skip" href="#manager-main">Skip to translations</a>
    @include('gemini-translator::partials.workspace')
</body>
</html>
