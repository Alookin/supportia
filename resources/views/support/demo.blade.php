{{-- resources/views/support/demo.blade.php --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Scénarios de démo
        </h2>
    </x-slot>

<div class="py-8 px-4">
    <div class="max-w-3xl mx-auto">

        <div class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-4 mb-8 text-sm text-amber-800">
            <strong>Mode démo</strong> — Cliquez sur "Tester ce scénario" pour ouvrir le formulaire pré-rempli avec un vrai cas client. L'IA classifiera et structurera automatiquement la demande.
        </div>

        <div class="grid gap-4">

            @php
            $scenarios = [
                [
                    'num'         => 1,
                    'tag'         => 'Import / Photos',
                    'tag_color'   => 'bg-blue-100 text-blue-700',
                    'client_name' => 'Dupont TP (288694)',
                    'description' => "Depuis deux jours les annonces du client n'ont pas de photos. Pouvez-vous vérifier l'import ?",
                ],
                [
                    'num'         => 2,
                    'tag'         => 'Connexion',
                    'tag_color'   => 'bg-red-100 text-red-700',
                    'client_name' => 'BAS WORLD (189346)',
                    'description' => "Le client n'arrive plus à se connecter depuis ce matin, il a essayé de réinitialiser son mot de passe mais rien ne fonctionne",
                ],
                [
                    'num'         => 3,
                    'tag'         => 'Facturation',
                    'tag_color'   => 'bg-yellow-100 text-yellow-700',
                    'client_name' => 'TRACTEURS DU SUD (54264)',
                    'description' => "Le client me dit qu'il a été facturé deux fois ce mois-ci pour le même abonnement, merci de vérifier",
                ],
                [
                    'num'         => 4,
                    'tag'         => 'Diffusion',
                    'tag_color'   => 'bg-orange-100 text-orange-700',
                    'client_name' => 'Auto Select 67 (461473)',
                    'description' => "Les annonces du client ne remontent plus sur Leboncoin depuis la semaine dernière",
                ],
                [
                    'num'         => 5,
                    'tag'         => 'Stats',
                    'tag_color'   => 'bg-purple-100 text-purple-700',
                    'client_name' => 'Garage Martin (852307)',
                    'description' => "Le client a besoin d'un export Excel de ses stats de diffusion sur les 6 derniers mois",
                ],
                [
                    'num'         => 6,
                    'tag'         => 'Nouveau client',
                    'tag_color'   => 'bg-green-100 text-green-700',
                    'client_name' => 'ITALIAN TRACTOR (1313605)',
                    'description' => "Bonjour, le client vient de signer, il faut mettre en place l'import de ses annonces depuis son site web",
                ],
            ];
            @endphp

            @foreach ($scenarios as $s)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex gap-4 items-start">
                <span class="w-7 h-7 rounded-full bg-gray-100 text-gray-500 text-xs font-bold flex items-center justify-center shrink-0 mt-0.5">
                    {{ $s['num'] }}
                </span>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $s['tag_color'] }}">
                            {{ $s['tag'] }}
                        </span>
                        <span class="text-xs text-gray-400 truncate">{{ $s['client_name'] }}</span>
                    </div>
                    <p class="text-sm text-gray-700 leading-relaxed">{{ $s['description'] }}</p>
                </div>
                <a href="{{ route('support.create', ['client_name' => $s['client_name'], 'description' => $s['description']]) }}"
                   class="shrink-0 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold transition-colors">
                    Tester →
                </a>
            </div>
            @endforeach

        </div>
    </div>
</div>

</x-app-layout>
