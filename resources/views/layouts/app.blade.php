<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Zeno</title>
        <!-- Favicon SVG inline -->
        <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%237C6CF0'/><text x='16' y='23' text-anchor='middle' font-family='Arial,sans-serif' font-size='20' font-weight='bold' fill='white'>Z</text></svg>">
        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=ibm-plex-sans:400,500,600,700|space-grotesk:500,600,700|ibm-plex-mono:400,500&display=swap" rel="stylesheet" />

        <script>
            // Thème : sombre par défaut, choix mémorisé (avant le rendu pour éviter le flash blanc)
            try { if ((localStorage.getItem('zeno-theme') || 'dark') === 'dark') document.documentElement.classList.add('dark'); }
            catch (e) { document.documentElement.classList.add('dark'); }
        </script>
        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen zeno-page">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white border-b border-gray-200 shadow-sm">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
