<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0a0a0a">
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        <x-inertia::head>
            <title>Sudaya Coffee</title>
        </x-inertia::head>
    </head>
    <body class="flex min-h-svh flex-col antialiased">
        <x-inertia::app />
    </body>
</html>
