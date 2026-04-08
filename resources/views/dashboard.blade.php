<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Accueil
        </h2>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- ── Bienvenue ───────────────────────────────────────── --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 px-8 py-7 flex items-center gap-5">
                <div class="w-14 h-14 rounded-2xl bg-blue-600 flex items-center justify-center text-white font-bold text-2xl shrink-0">
                    {{ mb_strtoupper(mb_substr($firstName, 0, 1)) }}
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Bonjour {{ $firstName }} 👋</h1>
                    <p class="mt-0.5 text-sm text-gray-500">Que souhaitez-vous faire aujourd'hui ?</p>
                </div>
            </div>

            {{-- ── 3 cartes d'action rapide ────────────────────────── --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">

                {{-- Signaler un problème --}}
                <a href="{{ route('support.create') }}"
                   class="group bg-blue-600 hover:bg-blue-700 rounded-2xl shadow-sm p-7 flex flex-col gap-4 transition-colors">
                    <div class="w-12 h-12 rounded-xl bg-white/20 flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <div>
                        <p class="font-bold text-white text-lg leading-snug">Signaler un problème</p>
                        <p class="mt-1 text-sm text-blue-100">Décrivez un problème client, l'IA s'occupe du reste</p>
                    </div>
                    <div class="mt-auto flex items-center gap-1 text-blue-100 text-sm font-medium group-hover:gap-2 transition-all">
                        Créer un ticket
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                        </svg>
                    </div>
                </a>

                {{-- Mes tickets --}}
                <a href="{{ route('support.my-tickets') }}"
                   class="group bg-white hover:bg-gray-50 rounded-2xl shadow-sm border border-gray-100 p-7 flex flex-col gap-4 transition-colors">
                    <div class="w-12 h-12 rounded-xl bg-indigo-50 flex items-center justify-center">
                        <svg class="w-6 h-6 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                        </svg>
                    </div>
                    <div>
                        <p class="font-bold text-gray-900 text-lg leading-snug">Mes tickets</p>
                        <p class="mt-1 text-sm text-gray-500">Suivez l'avancement de vos demandes</p>
                    </div>
                    <div class="mt-auto flex items-center gap-1 text-indigo-600 text-sm font-medium group-hover:gap-2 transition-all">
                        Voir mes tickets
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                        </svg>
                    </div>
                </a>

                {{-- Suivi global --}}
                <a href="{{ route('support.dashboard') }}"
                   class="group bg-white hover:bg-gray-50 rounded-2xl shadow-sm border border-gray-100 p-7 flex flex-col gap-4 transition-colors">
                    <div class="w-12 h-12 rounded-xl bg-emerald-50 flex items-center justify-center">
                        <svg class="w-6 h-6 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                        </svg>
                    </div>
                    <div>
                        <p class="font-bold text-gray-900 text-lg leading-snug">Suivi global</p>
                        <p class="mt-1 text-sm text-gray-500">Vue d'ensemble de l'activité support</p>
                    </div>
                    <div class="mt-auto flex items-center gap-1 text-emerald-600 text-sm font-medium group-hover:gap-2 transition-all">
                        Voir le dashboard
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                        </svg>
                    </div>
                </a>

            </div>

            {{-- ── Résumé rapide ───────────────────────────────────── --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-5">Votre activité</h3>

                <div class="flex flex-col sm:flex-row sm:items-start gap-6">

                    {{-- Stat semaine --}}
                    <div class="flex items-center gap-4 sm:w-56 shrink-0">
                        <div class="w-12 h-12 rounded-xl bg-violet-50 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 text-violet-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-3xl font-extrabold text-violet-600 leading-none">{{ $weekTickets }}</p>
                            <p class="mt-1 text-xs text-gray-500">ticket{{ $weekTickets > 1 ? 's' : '' }} cette semaine</p>
                        </div>
                    </div>

                    {{-- Séparateur --}}
                    <div class="hidden sm:block w-px bg-gray-100 self-stretch"></div>

                    {{-- Dernier ticket --}}
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-2">Dernier ticket</p>

                        @if($lastTicket)
                            @php
                                $statusConfig = match($lastTicket->status) {
                                    'created' => ['label' => 'Créé',       'class' => 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200', 'dot' => 'bg-emerald-500'],
                                    'pending' => ['label' => 'En attente', 'class' => 'bg-yellow-50 text-yellow-700 ring-1 ring-yellow-200',   'dot' => 'bg-yellow-400'],
                                    'queued'  => ['label' => 'En file',    'class' => 'bg-blue-50 text-blue-600 ring-1 ring-blue-200',         'dot' => 'bg-blue-400'],
                                    'failed'  => ['label' => 'Échec',      'class' => 'bg-red-50 text-red-600 ring-1 ring-red-200',            'dot' => 'bg-red-500'],
                                    default   => ['label' => '—',          'class' => 'bg-gray-100 text-gray-500',                             'dot' => 'bg-gray-300'],
                                };
                            @endphp
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-800 truncate">
                                        {{ $lastTicket->ai_title ?: $lastTicket->raw_description }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-gray-400">
                                        {{ $lastTicket->client_name ? 'Client : ' . $lastTicket->client_name . ' · ' : '' }}
                                        {{ $lastTicket->created_at->setTimezone('Europe/Paris')->diffForHumans() }}
                                    </p>
                                </div>
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium shrink-0 {{ $statusConfig['class'] }}">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $statusConfig['dot'] }}"></span>
                                    {{ $statusConfig['label'] }}
                                </span>
                            </div>
                        @else
                            <p class="text-sm text-gray-400">Aucun ticket pour le moment.</p>
                        @endif
                    </div>

                </div>
            </div>

        </div>
    </div>
</x-app-layout>
