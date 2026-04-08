@php
    $org = App\Models\Organization::where('is_active', true)->first();
    $branded = $org && $org->logo_path;
    $primaryColor = $org?->primary_color ?? '#2563eb';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $pageTitle ?? 'Zeno' }}</title>

        <!-- Favicon SVG inline -->
        <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%234F46E5'/><text x='16' y='23' text-anchor='middle' font-family='Arial,sans-serif' font-size='20' font-weight='bold' fill='white'>Z</text></svg>">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        @if($branded)
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;600&display=swap" rel="stylesheet">
        <style>
            .branded-label {
                font-family: 'Inter', system-ui, sans-serif;
                font-weight: 300;
                letter-spacing: 0.15em;
                text-transform: uppercase;
                font-size: 0.85rem;
                color: white;
            }
            .branded-form input[type="email"],
            .branded-form input[type="password"],
            .branded-form input[type="text"] {
                border-radius: 8px !important;
                border-color: #e2e8f0 !important;
                box-shadow: none !important;
            }
            .branded-form input[type="email"]:focus,
            .branded-form input[type="password"]:focus {
                border-color: {{ $primaryColor }} !important;
                box-shadow: 0 0 0 3px {{ $primaryColor }}33 !important;
            }
            .branded-form button[type="submit"] {
                background-color: {{ $primaryColor }} !important;
                border-radius: 8px !important;
                text-transform: none !important;
                letter-spacing: normal !important;
                font-size: 0.875rem !important;
                padding: 0.625rem 1.75rem !important;
            }
            .branded-form button[type="submit"]:hover,
            .branded-form button[type="submit"]:focus {
                background-color: color-mix(in srgb, {{ $primaryColor }}, black 12%) !important;
            }
        </style>
        @endif

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">

        @if($branded)
        {{-- ── Layout brandé organisation ──────────────────────────── --}}
        <div class="min-h-screen flex flex-col items-center justify-center px-4 py-12"
             style="background-color: {{ $primaryColor }}">

            {{-- Logo + titre --}}
            <div class="flex flex-col items-center mb-10">
                <img src="{{ asset($org->logo_path) }}"
                     alt="{{ $org->name }}"
                     style="max-width: 200px; max-height: 80px;"
                     class="w-auto object-contain drop-shadow-lg">
                <p class="branded-label mt-5">Portail Support</p>
            </div>

            {{-- Carte formulaire --}}
            <div class="branded-form w-full max-w-md bg-white rounded-2xl px-8 py-8"
                 style="box-shadow: 0 20px 60px rgba(0,0,0,0.25), 0 4px 16px rgba(0,0,0,0.15);">
                {{ $slot }}
            </div>

            <p class="mt-8" style="font-family:'Inter',system-ui,sans-serif; font-weight:300; letter-spacing:0.12em; text-transform:uppercase; font-size:0.72rem; color:rgba(255,255,255,0.45);">Propulsé par Zeno</p>
        </div>

        @else
        {{-- ── Layout Zeno par défaut ───────────────────────────────── --}}
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100">
            <div class="flex flex-col items-center">
                <a href="/" class="flex flex-col items-center gap-2">
                    <!-- Logo Zeno : carré bleu avec Z -->
                    <div class="w-16 h-16 bg-indigo-600 rounded-2xl flex items-center justify-center shadow-lg">
                        <span class="text-white text-3xl font-extrabold leading-none select-none">Z</span>
                    </div>
                    <span class="text-2xl font-bold text-gray-800 tracking-tight">Zeno</span>
                </a>
                <p class="mt-1 text-sm text-gray-500">Portail de support assisté par IA</p>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
        @endif

    </body>
</html>
