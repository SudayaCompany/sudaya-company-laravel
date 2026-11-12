<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0b2b16">
        @php($meta = \App\Support\PublicMeta::for(request()->path()))
        @if ($meta)
            {{-- Pratinjau tautan (WhatsApp, Instagram, Facebook) dan mesin pencari: tanpa SSR, jadi dari server. --}}
            <meta name="description" content="{{ $meta['description'] }}">
            @if ($meta['canonical'])
                <link rel="canonical" href="{{ $meta['canonical'] }}">
            @endif
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="Sudaya Coffee">
            <meta property="og:locale" content="id_ID">
            <meta property="og:title" content="{{ $meta['title'] }}">
            <meta property="og:description" content="{{ $meta['description'] }}">
            <meta property="og:url" content="{{ $meta['url'] }}">
            <meta property="og:image" content="{{ $meta['image'] }}">
            <meta name="twitter:card" content="summary_large_image">
        @else
            <meta name="robots" content="noindex, nofollow">
        @endif
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
