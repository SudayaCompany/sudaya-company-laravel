<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>@yield('title') · Sudaya Coffee</title>
        {{-- Tanpa Vite dan tanpa data akun: halaman ini harus tampil walau database atau build bermasalah. --}}
        <style>
            body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: ui-sans-serif, system-ui, sans-serif; color: #0a0a0a; background: #fff; text-align: center; padding: 24px; box-sizing: border-box; }
            main { max-width: 24rem; display: flex; flex-direction: column; gap: 12px; align-items: center; }
            h1 { font-size: 1.25rem; margin: 0; }
            p { margin: 0; font-size: .875rem; color: #737373; }
            a { margin-top: 8px; font-size: .875rem; color: #0a0a0a; border: 1px solid #e5e5e5; border-radius: 10px; padding: 8px 12px; text-decoration: none; }
        </style>
    </head>
    <body>
        <main>
            <h1>@yield('title')</h1>
            <p>@yield('message')</p>
            <a href="/">Kembali ke awal</a>
        </main>
    </body>
</html>
