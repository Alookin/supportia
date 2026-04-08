<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Suivi des tickets
            </h2>
            <p class="mt-0.5 text-sm text-gray-500">
                Vue d'ensemble de l'activité SupportIA pour {{ $orgName }}
            </p>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- ── 4 cartes stats ─────────────────────────────────── --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">

                {{-- Total --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 flex items-start gap-4">
                    <div class="shrink-0 w-12 h-12 rounded-xl bg-indigo-50 flex items-center justify-center">
                        <svg class="w-6 h-6 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-3-3v6M4.5 19.5h15a2.25 2.25 0 000-4.5H4.5a2.25 2.25 0 000 4.5zM4.5 9.75h15a2.25 2.25 0 000-4.5H4.5a2.25 2.25 0 000 4.5z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Total tickets (depuis le lancement)</p>
                        <p class="mt-1 text-4xl font-extrabold text-gray-900 leading-none">{{ $totalTickets }}</p>
                        <p class="mt-1 text-xs text-gray-400">créés via SupportIA</p>
                    </div>
                </div>

                {{-- Aujourd'hui --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 flex items-start gap-4">
                    <div class="shrink-0 w-12 h-12 rounded-xl bg-violet-50 flex items-center justify-center">
                        <svg class="w-6 h-6 text-violet-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Aujourd'hui ({{ now()->setTimezone('Europe/Paris')->format('d/m/Y') }})</p>
                        <p class="mt-1 text-4xl font-extrabold text-violet-600 leading-none">{{ $todayTickets }}</p>
                        <p class="mt-1 text-xs text-gray-400">{{ now()->translatedFormat('l d F') }}</p>
                    </div>
                </div>

                {{-- Taux auto --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 flex items-start gap-4">
                    <div class="shrink-0 w-12 h-12 rounded-xl bg-emerald-50 flex items-center justify-center">
                        <svg class="w-6 h-6 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Classification auto</p>
                        <p class="mt-1 text-4xl font-extrabold text-emerald-600 leading-none">{{ $autoRate }}<span class="text-xl font-bold">%</span></p>
                        <div class="mt-2 w-full bg-gray-100 rounded-full h-1.5">
                            <div class="bg-emerald-500 h-1.5 rounded-full transition-all" style="width: {{ $autoRate }}%"></div>
                        </div>
                        <p class="mt-1 text-xs text-gray-400">{{ $autoClassified }} auto / {{ $totalTickets }} total</p>
                    </div>
                </div>

                {{-- Top catégorie --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 flex items-start gap-4">
                    <div class="shrink-0 w-12 h-12 rounded-xl bg-amber-50 flex items-center justify-center">
                        <svg class="w-6 h-6 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Catégorie principale</p>
                        <p class="mt-1 text-lg font-extrabold text-gray-900 leading-snug break-words">{{ $topCategoryLabel }}</p>
                        <p class="mt-1 text-xs text-gray-400">la plus fréquente</p>
                    </div>
                </div>

            </div>

            {{-- ── Graphiques côte à côte ──────────────────────────── --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

                {{-- Barres horizontales — top 5 catégories --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-5">Top 5 catégories</h3>

                    @if($topCategories->isEmpty())
                        <p class="text-sm text-gray-400 text-center py-8">Aucune donnée</p>
                    @else
                        <div class="space-y-4">
                            @php
                                $barColors = ['bg-indigo-500', 'bg-violet-400', 'bg-sky-400', 'bg-emerald-400', 'bg-amber-400'];
                            @endphp
                            @foreach($topCategories as $i => $cat)
                                @php $pct = round($cat['count'] / $maxCategoryCount * 100); @endphp
                                <div>
                                    <div class="flex justify-between items-baseline mb-1.5">
                                        <span class="text-sm text-gray-700 truncate max-w-[75%]" title="{{ $cat['label'] }}">
                                            {{ $cat['label'] }}
                                        </span>
                                        <span class="text-sm font-bold text-gray-800 ml-2 shrink-0">{{ $cat['count'] }}</span>
                                    </div>
                                    <div class="w-full bg-gray-100 rounded-full h-3">
                                        <div class="{{ $barColors[$i] ?? 'bg-indigo-400' }} h-3 rounded-full transition-all"
                                             style="width: {{ $pct }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Barres verticales — tickets par jour (7 jours) --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-5">Tickets des 7 derniers jours</h3>

                    @if($ticketsByDay->sum('count') === 0)
                        <p class="text-sm text-gray-400 text-center py-8">Aucun ticket cette semaine</p>
                    @else
                        <div class="flex items-end gap-2" style="height: 120px;">
                            @foreach($ticketsByDay as $day)
                                @php
                                    $barPx = $maxDayCount > 0 ? max(3, round($day['count'] / $maxDayCount * 100)) : 3;
                                    $isToday = $day['label'] === now()->format('d/m');
                                @endphp
                                <div class="flex-1 flex flex-col items-center gap-1">
                                    <span class="text-xs font-semibold {{ $day['count'] > 0 ? 'text-gray-600' : 'text-gray-300' }}">
                                        {{ $day['count'] > 0 ? $day['count'] : '' }}
                                    </span>
                                    <div class="w-full rounded-t-lg {{ $isToday ? 'bg-indigo-500' : 'bg-indigo-200' }} transition-all"
                                         style="height: {{ $barPx }}px"></div>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex gap-2 mt-2">
                            @foreach($ticketsByDay as $day)
                                @php $isToday = $day['label'] === now()->format('d/m'); @endphp
                                <div class="flex-1 text-center">
                                    <span class="text-xs {{ $isToday ? 'text-indigo-600 font-semibold' : 'text-gray-400' }}">
                                        {{ $day['label'] }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

            </div>

            {{-- ── Graphiques supplémentaires ──────────────────────── --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

                {{-- Tickets par catégorie — top 10 --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-5">Tickets par catégorie <span class="text-gray-400 font-normal">(top 10)</span></h3>
                    @if($categoryDistribution->isEmpty())
                        <p class="text-sm text-gray-400 text-center py-8">Aucune donnée</p>
                    @else
                        <div class="space-y-3">
                            @foreach($categoryDistribution as $i => $cat)
                                @php $pct = round($cat['count'] / $maxCategoryDistCount * 100); @endphp
                                <div>
                                    <div class="flex justify-between items-baseline mb-1">
                                        <span class="text-xs text-gray-600 truncate max-w-[75%]" title="{{ $cat['label'] }}">{{ $cat['label'] }}</span>
                                        <span class="text-xs font-bold text-gray-800 ml-1 shrink-0">{{ $cat['count'] }}</span>
                                    </div>
                                    <div class="w-full bg-gray-100 rounded-full h-2">
                                        <div class="bg-indigo-400 h-2 rounded-full" style="width: {{ $pct }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Tickets par priorité --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-5">Répartition par priorité</h3>
                    @if($ticketsByPriority->isEmpty())
                        <p class="text-sm text-gray-400 text-center py-8">Aucune donnée</p>
                    @else
                        <div class="space-y-4">
                            @foreach($ticketsByPriority as $item)
                                @php $pct = round($item['count'] / $maxPriorityCount * 100); @endphp
                                <div>
                                    <div class="flex justify-between items-baseline mb-1.5">
                                        <span class="text-sm text-gray-700">{{ $item['label'] }}</span>
                                        <span class="text-sm font-bold text-gray-800">{{ $item['count'] }}</span>
                                    </div>
                                    <div class="w-full bg-gray-100 rounded-full h-3">
                                        <div class="{{ $item['color'] }} h-3 rounded-full transition-all" style="width: {{ $pct }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Temps moyen de résolution --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-1">Temps moyen de résolution</h3>
                    <p class="text-xs text-gray-400 mb-5">Tickets au statut "Créé" — de la saisie à GLPI</p>
                    @if($resolutionTimes->isEmpty())
                        <p class="text-sm text-gray-400 text-center py-8">Pas encore de données</p>
                    @else
                        <div class="space-y-3">
                            @foreach($resolutionTimes as $item)
                                @php $pct = round($item['avg_seconds'] / $maxResolutionSeconds * 100); @endphp
                                <div>
                                    <div class="flex justify-between items-baseline mb-1">
                                        <span class="text-xs text-gray-600 truncate max-w-[65%]" title="{{ $item['label'] }}">{{ $item['label'] }}</span>
                                        <span class="text-xs font-bold text-emerald-600 ml-1 shrink-0">{{ $item['display'] }}</span>
                                    </div>
                                    <div class="w-full bg-gray-100 rounded-full h-2">
                                        <div class="bg-emerald-400 h-2 rounded-full" style="width: {{ $pct }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

            </div>

            {{-- ── Tableau des 20 derniers tickets ───────────────── --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700">20 derniers tickets</h3>
                    @if($tickets->isNotEmpty())
                        <span class="text-xs text-gray-400">{{ $tickets->count() }} entrée(s)</span>
                    @endif
                </div>

                @if($tickets->isEmpty())
                    <div class="px-6 py-16 text-center">
                        <svg class="w-10 h-10 text-gray-200 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                        </svg>
                        <p class="text-sm text-gray-400">Aucun ticket créé pour le moment.</p>
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
                                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-400 min-w-[100px]">Confiance IA</th>
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
                                            'created' => [
                                                'label' => 'Créé',
                                                'class' => 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200',
                                                'dot'   => 'bg-emerald-500',
                                            ],
                                            'pending' => [
                                                'label' => 'En attente',
                                                'class' => 'bg-yellow-50 text-yellow-700 ring-1 ring-yellow-200',
                                                'dot'   => 'bg-yellow-400',
                                            ],
                                            'queued'  => [
                                                'label' => 'En file',
                                                'class' => 'bg-blue-50 text-blue-600 ring-1 ring-blue-200',
                                                'dot'   => 'bg-blue-400',
                                            ],
                                            'failed'  => [
                                                'label' => 'Échec',
                                                'class' => 'bg-red-50 text-red-600 ring-1 ring-red-200',
                                                'dot'   => 'bg-red-500',
                                            ],
                                            default   => [
                                                'label' => $ticket->status ?? '—',
                                                'class' => 'bg-gray-100 text-gray-500',
                                                'dot'   => 'bg-gray-300',
                                            ],
                                        };

                                        $confidencePct = $ticket->ai_confidence !== null
                                            ? round($ticket->ai_confidence * 100)
                                            : null;

                                        $confidenceBarClass = match(true) {
                                            $confidencePct === null   => '',
                                            $confidencePct >= 70      => 'bg-emerald-500',
                                            $confidencePct >= 40      => 'bg-yellow-400',
                                            default                   => 'bg-red-400',
                                        };

                                        $confidenceTextClass = match(true) {
                                            $confidencePct === null   => 'text-gray-300',
                                            $confidencePct >= 70      => 'text-emerald-600',
                                            $confidencePct >= 40      => 'text-yellow-600',
                                            default                   => 'text-red-500',
                                        };

                                    @endphp
                                    <tr class="hover:bg-indigo-50/40 transition-colors cursor-pointer"
                                        onclick="window.location='{{ route('support.ticket-detail', $ticket->id) }}'">

                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="text-gray-500 text-xs">{{ $ticket->created_at->setTimezone('Europe/Paris')->format('d/m') }}</span>
                                            <span class="text-gray-400 text-xs ml-1">{{ $ticket->created_at->setTimezone('Europe/Paris')->format('H:i') }}</span>
                                        </td>

                                        <td class="px-4 py-3 whitespace-nowrap max-w-[130px] truncate text-gray-700 text-sm" title="{{ $ticket->client_name }}">
                                            {{ $ticket->client_name ?: '—' }}
                                        </td>

                                        <td class="px-4 py-3 max-w-[200px]">
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
                                                <span class="text-gray-300">—</span>
                                            @endif
                                        </td>

                                        <td class="px-4 py-3 whitespace-nowrap min-w-[100px]">
                                            @if($confidencePct !== null)
                                                <div class="flex items-center gap-2">
                                                    <div class="flex-1 bg-gray-100 rounded-full h-2">
                                                        <div class="{{ $confidenceBarClass }} h-2 rounded-full" style="width: {{ $confidencePct }}%"></div>
                                                    </div>
                                                    <span class="text-xs font-semibold {{ $confidenceTextClass }} w-8 text-right">{{ $confidencePct }}%</span>
                                                </div>
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
