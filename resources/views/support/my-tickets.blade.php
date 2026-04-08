<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Mes tickets
            </h2>
            <p class="mt-0.5 text-sm text-gray-500">
                Tous les tickets que vous avez créés via SupportIA
            </p>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- ── 3 stats ─────────────────────────────────────────── --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 flex items-start gap-4">
                    <div class="shrink-0 w-11 h-11 rounded-xl bg-indigo-50 flex items-center justify-center">
                        <svg class="w-5 h-5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Total</p>
                        <p class="mt-1 text-4xl font-extrabold text-gray-900 leading-none">{{ $myTotal }}</p>
                        <p class="mt-1 text-xs text-gray-400">tickets créés</p>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 flex items-start gap-4">
                    <div class="shrink-0 w-11 h-11 rounded-xl bg-violet-50 flex items-center justify-center">
                        <svg class="w-5 h-5 text-violet-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Cette semaine</p>
                        <p class="mt-1 text-4xl font-extrabold text-violet-600 leading-none">{{ $myThisWeek }}</p>
                        <p class="mt-1 text-xs text-gray-400">depuis lundi</p>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 flex items-start gap-4">
                    <div class="shrink-0 w-11 h-11 rounded-xl bg-amber-50 flex items-center justify-center">
                        <svg class="w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">En attente</p>
                        <p class="mt-1 text-4xl font-extrabold text-amber-500 leading-none">{{ $myPending }}</p>
                        <p class="mt-1 text-xs text-gray-400">pas encore dans GLPI</p>
                    </div>
                </div>

            </div>

            {{-- ── Tableau des tickets ─────────────────────────────── --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700">Tous mes tickets</h3>
                    <a href="{{ route('support.create') }}"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Nouveau ticket
                    </a>
                </div>

                @if($tickets->isEmpty())
                    <div class="px-6 py-16 text-center">
                        <svg class="w-10 h-10 text-gray-200 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                        </svg>
                        <p class="text-sm text-gray-400 mb-4">Vous n'avez pas encore créé de ticket.</p>
                        <a href="{{ route('support.create') }}"
                           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Signaler un problème
                        </a>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-gray-50 border-b border-gray-100">
                                <tr>
                                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-400">Date</th>
                                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-400">Client</th>
                                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-400">Titre</th>
                                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-400">Catégorie</th>
                                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-400">Priorité</th>
                                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-400">Statut</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @foreach($tickets as $ticket)
                                    @php
                                        $categoryLabel = $categories->get($ticket->ai_category_slug, $ticket->ai_category_slug ?? '—');

                                        $priorityConfig = match($ticket->ai_priority) {
                                            1 => ['label' => 'Très basse', 'class' => 'bg-gray-100 text-gray-500 ring-1 ring-gray-200'],
                                            2 => ['label' => 'Basse',      'class' => 'bg-blue-50 text-blue-600 ring-1 ring-blue-200'],
                                            3 => ['label' => 'Normale',    'class' => 'bg-yellow-50 text-yellow-700 ring-1 ring-yellow-200'],
                                            4 => ['label' => 'Haute',      'class' => 'bg-orange-50 text-orange-700 ring-1 ring-orange-200'],
                                            5 => ['label' => 'Critique',   'class' => 'bg-red-50 text-red-600 ring-1 ring-red-200'],
                                            default => ['label' => '—',    'class' => 'bg-gray-100 text-gray-400'],
                                        };

                                        $statusConfig = match($ticket->status) {
                                            'created' => ['label' => 'Créé',       'class' => 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200', 'dot' => 'bg-emerald-500'],
                                            'pending' => ['label' => 'En attente', 'class' => 'bg-yellow-50 text-yellow-700 ring-1 ring-yellow-200',   'dot' => 'bg-yellow-400'],
                                            'queued'  => ['label' => 'En file',    'class' => 'bg-blue-50 text-blue-600 ring-1 ring-blue-200',         'dot' => 'bg-blue-400'],
                                            'failed'  => ['label' => 'Échec',      'class' => 'bg-red-50 text-red-600 ring-1 ring-red-200',            'dot' => 'bg-red-500'],
                                            default   => ['label' => $ticket->status ?? '—', 'class' => 'bg-gray-100 text-gray-500',                   'dot' => 'bg-gray-300'],
                                        };

                                    @endphp
                                    <tr class="hover:bg-indigo-50/40 transition-colors cursor-pointer"
                                        onclick="window.location='{{ route('support.ticket-detail', $ticket->id) }}'">

                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="text-gray-600 text-xs font-medium">{{ $ticket->created_at->setTimezone('Europe/Paris')->format('d/m/Y') }}</span>
                                            <span class="text-gray-400 text-xs ml-1">{{ $ticket->created_at->setTimezone('Europe/Paris')->format('H:i') }}</span>
                                        </td>

                                        <td class="px-4 py-3 whitespace-nowrap max-w-[130px] truncate text-gray-700 text-sm" title="{{ $ticket->client_name }}">
                                            {{ $ticket->client_name ?: '—' }}
                                        </td>

                                        <td class="px-4 py-3 max-w-[240px]">
                                            <span class="text-gray-800 text-sm leading-snug line-clamp-2" title="{{ $ticket->ai_title }}">
                                                {{ $ticket->ai_title ?: $ticket->raw_description }}
                                            </span>
                                        </td>

                                        <td class="px-4 py-3 whitespace-nowrap max-w-[160px] truncate text-gray-500 text-xs" title="{{ $categoryLabel }}">
                                            {{ $categoryLabel }}
                                        </td>

                                        <td class="px-4 py-3 whitespace-nowrap">
                                            @if($ticket->ai_priority)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $priorityConfig['class'] }}">
                                                    {{ $priorityConfig['label'] }}
                                                </span>
                                            @else
                                                <span class="text-gray-300 text-xs">—</span>
                                            @endif
                                        </td>

                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium {{ $statusConfig['class'] }}">
                                                <span class="w-1.5 h-1.5 rounded-full {{ $statusConfig['dot'] }}"></span>
                                                {{ $statusConfig['label'] }}
                                            </span>
                                        </td>

                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
