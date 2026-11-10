<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0a0a0a">
        @if (\App\Support\StaffApp::offeredOn(request()->path()))
            {{-- Aplikasi staf (PWA): bisa dipasang di HP; halaman pelanggan tidak menawarkan pemasangan. --}}
            <link rel="manifest" href="/manifest.webmanifest">
            <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
            <meta name="mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-title" content="Sudaya">
            <meta name="apple-mobile-web-app-status-bar-style" content="black">
        @endif
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
