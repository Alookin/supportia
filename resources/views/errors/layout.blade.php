<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — Zeno</title>
    <script>
        try { if ((localStorage.getItem('zeno-theme') || 'dark') === 'dark') document.documentElement.classList.add('dark'); }
        catch (e) { document.documentElement.classList.add('dark'); }
    </script>
    @vite(['resources/css/app.css'])
</head>
<body class="font-sans antialiased zeno-page min-h-screen flex items-center justify-center p-6">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 max-w-md w-full p-8 text-center">
        <div class="w-12 h-12 rounded-xl bg-blue-600 text-white font-bold text-xl flex items-center justify-center mx-auto mb-5">Z</div>
        <p class="text-sm font-semibold text-blue-600 mb-1">Erreur {{ $code }}</p>
        <h1 class="text-xl font-bold text-gray-900 mb-2">{{ $title }}</h1>
        <p class="text-sm text-gray-600 mb-6">{{ $message }}</p>
        <div class="flex gap-3 justify-center">
            <a href="{{ url('/support') }}" class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold">Retour à l'accueil</a>
            <a href="javascript:history.back()" class="px-4 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-semibold">Page précédente</a>
        </div>
    </div>
</body>
</html>
