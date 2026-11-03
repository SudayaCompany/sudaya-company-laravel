<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        <x-inertia::head>
            <title>Sudaya Coffee</title>
        </x-inertia::head>
    </head>
    <body class="min-h-svh bg-white text-neutral-950 antialiased">
        <x-inertia::app />
    </body>
</html>
