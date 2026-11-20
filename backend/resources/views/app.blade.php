<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#4f46e5">

        <title inertia>{{ config('app.name', 'ISP Self-Care') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-slate-950 text-slate-100">
        @inertia
        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('/sw.js').catch(err => console.error(err));
                });
            }
        </script>
    </body>
</html>
