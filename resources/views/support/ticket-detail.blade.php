@php use Illuminate\Support\Facades\Storage; @endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('support.my-tickets') }}"
               class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700 transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                Mes tickets
            </a>
            <span class="text-gray-300">/</span>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight truncate">
                {{ $ticket->ai_title ?: 'Ticket #' . $ticket->id }}
            </h2>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- ── En-tête ticket ──────────────────────────────────── --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h1 class="text-xl font-bold text-gray-900 leading-snug">
                            {{ $ticket->ai_title ?: $ticket->raw_description }}
                        </h1>
                        <p class="mt-1 text-sm text-gray-400">
                            Créé le {{ $ticket->created_at->setTimezone('Europe/Paris')->translatedFormat('l d F Y à H:i') }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        @php
                            $statusConfig = match($ticket->status) {
                                'created' => ['label' => 'Créé',       'class' => 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200', 'dot' => 'bg-emerald-500'],
                                'pending' => ['label' => 'En attente', 'class' => 'bg-yellow-50 text-yellow-700 ring-1 ring-yellow-200',   'dot' => 'bg-yellow-400'],
                                'queued'  => ['label' => 'En file',    'class' => 'bg-blue-50 text-blue-600 ring-1 ring-blue-200',         'dot' => 'bg-blue-400'],
                                'failed'  => ['label' => 'Échec',      'class' => 'bg-red-50 text-red-600 ring-1 ring-red-200',            'dot' => 'bg-red-500'],
                                default   => ['label' => $ticket->status ?? '—', 'class' => 'bg-gray-100 text-gray-500', 'dot' => 'bg-gray-300'],
                            };
                            $priorityConfig = match($ticket->ai_priority) {
                                1 => ['label' => 'Très basse', 'class' => 'bg-gray-100 text-gray-500 ring-1 ring-gray-200'],
                                2 => ['label' => 'Basse',      'class' => 'bg-blue-50 text-blue-600 ring-1 ring-blue-200'],
                                3 => ['label' => 'Normale',    'class' => 'bg-yellow-50 text-yellow-700 ring-1 ring-yellow-200'],
                                4 => ['label' => 'Haute',      'class' => 'bg-orange-50 text-orange-700 ring-1 ring-orange-200'],
                                5 => ['label' => 'Critique',   'class' => 'bg-red-50 text-red-600 ring-1 ring-red-200'],
                                default => null,
                            };
                        @endphp

                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium {{ $statusConfig['class'] }}">
                            <span class="w-2 h-2 rounded-full {{ $statusConfig['dot'] }}"></span>
                            {{ $statusConfig['label'] }}
                        </span>

                        @if($priorityConfig)
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $priorityConfig['class'] }}">
                                {{ $priorityConfig['label'] }}
                            </span>
                        @endif
                    </div>
                </div>

                @if($ticket->status === 'failed' && $ticket->glpi_last_error)
                    <div class="mt-4 p-3 bg-red-50 border border-red-100 rounded-lg text-xs text-red-600 font-mono">
                        Erreur : {{ $ticket->glpi_last_error }}
                    </div>
                @endif
            </div>

            {{-- ── Informations ────────────────────────────────────── --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <h2 class="text-sm font-semibold text-gray-700 mb-4">Informations</h2>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-400">Client</dt>
                        @if($ticket->client_ids && count($ticket->client_ids) > 1)
                            <dd class="mt-1 text-sm text-gray-800 space-y-0.5">
                                @foreach($ticket->client_ids as $c)
                                    <span class="block">{{ $c['id'] }}{{ $c['name'] ? ' – ' . $c['name'] : '' }}</span>
                                @endforeach
                            </dd>
                        @else
                            <dd class="mt-1 text-sm text-gray-800">{{ $ticket->client_name ?: '—' }}</dd>
                        @endif
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-400">Catégorie</dt>
                        <dd class="mt-1 text-sm text-gray-800">{{ $categoryLabel }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-400">Commercial</dt>
                        <dd class="mt-1 text-sm text-gray-800">{{ $ticket->user?->name ?? '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-400">Confiance IA</dt>
                        <dd class="mt-1">
                            @if($ticket->ai_confidence !== null)
                                @php
                                    $pct = round($ticket->ai_confidence * 100);
                                    $barClass = $pct >= 70 ? 'bg-emerald-500' : ($pct >= 40 ? 'bg-yellow-400' : 'bg-red-400');
                                    $textClass = $pct >= 70 ? 'text-emerald-600' : ($pct >= 40 ? 'text-yellow-600' : 'text-red-500');
                                @endphp
                                <div class="flex items-center gap-2">
                                    <div class="w-24 bg-gray-100 rounded-full h-2">
                                        <div class="{{ $barClass }} h-2 rounded-full" style="width: {{ $pct }}%"></div>
                                    </div>
                                    <span class="text-sm font-semibold {{ $textClass }}">{{ $pct }}%</span>
                                </div>
                            @else
                                <span class="text-sm text-gray-400">—</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-400">Classification</dt>
                        <dd class="mt-1">
                            @if($ticket->ai_provider === 'claude')
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700 ring-1 ring-blue-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                                    Claude IA
                                </span>
                            @elseif($ticket->ai_provider)
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                    <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                    Fallback mots-clés
                                </span>
                            @else
                                <span class="text-sm text-gray-400">—</span>
                            @endif
                        </dd>
                    </div>

                    @if($ticket->was_modified_by_user)
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wider text-gray-400">Modification</dt>
                            <dd class="mt-1">
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 ring-1 ring-amber-200">
                                    Modifié par le commercial
                                </span>
                            </dd>
                        </div>
                    @endif

                </dl>
            </div>

            {{-- ── Description structurée ─────────────────────────── --}}
            @if($ticket->ai_body)
                @php
                    // 1. Échapper d'abord pour neutraliser tout HTML brut
                    $bodyHtml = e($ticket->ai_body);
                    // 2. **texte** → <strong>texte</strong>
                    $bodyHtml = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $bodyHtml);
                    // 3. - item en début de ligne → • item
                    $bodyHtml = preg_replace('/^- /m', '• ', $bodyHtml);
                    // 4. Sauts de ligne → <br>
                    $bodyHtml = nl2br($bodyHtml);
                @endphp
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h2 class="text-sm font-semibold text-gray-700 mb-4">Description</h2>
                    <div class="text-sm text-gray-700 leading-relaxed">{!! $bodyHtml !!}</div>
                </div>
            @endif

            {{-- ── Estimation temps de traitement ─────────────────── --}}
            @if($ticket->ai_category_slug)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h2 class="text-sm font-semibold text-gray-700 mb-3">Temps de traitement estimé</h2>
                    @if($estimate['hours'] !== null)
                        @php
                            $h = $estimate['hours'];
                            $display = $h < 24
                                ? '~' . round($h) . ' heure' . (round($h) > 1 ? 's' : '')
                                : '~' . round($h / 24) . ' jour' . (round($h / 24) > 1 ? 's' : '');
                        @endphp
                        <p class="text-2xl font-bold text-indigo-600">{{ $display }}</p>
                        <p class="mt-1 text-xs text-gray-400">
                            Basé sur les {{ $estimate['count'] }} tickets précédents de cette catégorie
                        </p>
                    @else
                        <p class="text-sm text-gray-400">Estimation non disponible — pas assez d'historique</p>
                    @endif
                </div>
            @endif

            {{-- ── Suivi du ticket ─────────────────────────────────── --}}
            @if($ticket->glpi_ticket_id)
                @if($glpiStatus === null)
                    {{-- GLPI indisponible --}}
                    <div class="flex items-center gap-3 px-4 py-3 bg-amber-50 border border-amber-100 rounded-xl text-sm text-amber-700">
                        <svg class="w-4 h-4 shrink-0 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                        Suivi temporairement indisponible — GLPI ne répond pas.
                        <a href="{{ str_replace('/apirest.php', '', rtrim($ticket->organization?->glpi_api_url ?? '', '/')) }}/front/ticket.form.php?id={{ $ticket->glpi_ticket_id }}"
                           target="_blank" class="ml-auto text-xs text-amber-600 underline hover:text-amber-800">
                            Ouvrir dans GLPI
                        </a>
                    </div>
                @else
                    @php
                        $glpiStatusConfig = match($glpiStatus['status']) {
                            1 => ['class' => 'bg-gray-100 text-gray-600 ring-1 ring-gray-200',        'dot' => 'bg-gray-400'],
                            2 => ['class' => 'bg-blue-50 text-blue-700 ring-1 ring-blue-200',          'dot' => 'bg-blue-500'],
                            3 => ['class' => 'bg-blue-50 text-blue-700 ring-1 ring-blue-200',          'dot' => 'bg-blue-500'],
                            4 => ['class' => 'bg-orange-50 text-orange-700 ring-1 ring-orange-200',    'dot' => 'bg-orange-400'],
                            5 => ['class' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200', 'dot' => 'bg-emerald-500'],
                            6 => ['class' => 'bg-gray-100 text-gray-500 ring-1 ring-gray-300',         'dot' => 'bg-gray-500'],
                            default => ['class' => 'bg-gray-100 text-gray-500 ring-1 ring-gray-200',   'dot' => 'bg-gray-400'],
                        };
                        $resolutionDate = ! empty($glpiStatus['resolution_date'])
                            ? \Illuminate\Support\Carbon::parse($glpiStatus['resolution_date'])->setTimezone('Europe/Paris')
                            : null;
                    @endphp
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">

                        {{-- En-tête : titre + badge statut + lien GLPI --}}
                        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                            <h2 class="text-sm font-semibold text-gray-700">Suivi du ticket</h2>
                            <div class="flex items-center gap-3">
                                @if($glpiStatus['status'] > 0)
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium {{ $glpiStatusConfig['class'] }}">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $glpiStatusConfig['dot'] }}"></span>
                                        {{ $glpiStatus['status_label'] }}
                                    </span>
                                @endif
                                <a href="{{ str_replace('/apirest.php', '', rtrim($ticket->organization?->glpi_api_url ?? '', '/')) }}/front/ticket.form.php?id={{ $ticket->glpi_ticket_id }}"
                                   target="_blank"
                                   class="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                    </svg>
                                    Ticket #{{ $ticket->glpi_ticket_id }}
                                </a>
                            </div>
                        </div>

                        {{-- Méta : technicien + date résolution --}}
                        @if($glpiStatus['assigned_to'] || $resolutionDate)
                            <div class="flex flex-wrap gap-x-6 gap-y-1 mb-4 text-xs text-gray-500">
                                @if($glpiStatus['assigned_to'])
                                    <span>
                                        <span class="font-semibold text-gray-400 uppercase tracking-wider">Technicien</span>
                                        &nbsp;{{ $glpiStatus['assigned_to'] }}
                                    </span>
                                @endif
                                @if($resolutionDate)
                                    <span>
                                        <span class="font-semibold text-gray-400 uppercase tracking-wider">Résolu le</span>
                                        &nbsp;{{ $resolutionDate->translatedFormat('d F Y à H:i') }}
                                    </span>
                                @endif
                            </div>
                        @endif

                        {{-- Fil des followups --}}
                        @if(empty($glpiStatus['followups']))
                            <p class="text-sm text-gray-400">Aucune réponse pour le moment.</p>
                        @else
                            <div class="space-y-4">
                                @foreach($glpiStatus['followups'] as $fu)
                                    @php
                                        $fuDate = ! empty($fu['date']) && $fu['date'] !== '0000-00-00 00:00:00'
                                            ? \Illuminate\Support\Carbon::parse($fu['date'])->setTimezone('Europe/Paris')
                                            : null;
                                        $fuAuthor  = $fu['author'] ?? null;
                                        $fuInitial = $fuAuthor ? strtoupper(mb_substr($fuAuthor, 0, 1)) : '?';
                                    @endphp
                                    <div class="flex gap-3">
                                        <div class="shrink-0 w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center">
                                            <span class="text-xs font-bold text-indigo-600">{{ $fuInitial }}</span>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex flex-wrap items-baseline gap-2 mb-1">
                                                <span class="text-xs font-semibold text-indigo-600">
                                                    {{ $fuAuthor ?? 'Technicien' }}
                                                </span>
                                                @if($fuDate)
                                                    <span class="text-xs text-gray-400">
                                                        {{ $fuDate->translatedFormat('d F Y à H:i') }}
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="text-sm text-gray-700 whitespace-pre-wrap bg-indigo-50/50 rounded-lg px-3 py-2 border border-indigo-100/60">{{ $fu['content'] }}</div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                    </div>
                @endif
            @endif

            {{-- ── Description originale ──────────────────────────── --}}
            @if($ticket->raw_description)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h2 class="text-sm font-semibold text-gray-700 mb-1">Description originale</h2>
                    <p class="text-xs text-gray-400 mb-4">Texte saisi par le commercial, avant traitement IA</p>
                    <div class="bg-gray-50 rounded-xl p-4 text-sm text-gray-600 leading-relaxed whitespace-pre-wrap border border-gray-100">{{ $ticket->raw_description }}</div>
                </div>
            @endif

            {{-- ── Capture d'écran ────────────────────────────────── --}}
            @if($ticket->screenshot_path)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6"
                     x-data="{ lightbox: false }">
                    <h2 class="text-sm font-semibold text-gray-700 mb-4">Capture d'écran</h2>
                    <img src="{{ Storage::url($ticket->screenshot_path) }}"
                         alt="Capture d'écran du ticket"
                         @click="lightbox = true"
                         class="max-w-full max-h-96 rounded-xl border border-gray-100 shadow-sm hover:opacity-90 transition-opacity cursor-zoom-in">
                    <p class="mt-2 text-xs text-gray-400">Cliquez pour agrandir</p>

                    {{-- Lightbox overlay --}}
                    <div x-show="lightbox"
                         x-transition.opacity
                         @click.self="lightbox = false"
                         @keydown.escape.window="lightbox = false"
                         class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
                         style="display:none">
                        <button @click="lightbox = false"
                                class="absolute top-4 right-4 text-white/80 hover:text-white text-3xl leading-none">&times;</button>
                        <img src="{{ Storage::url($ticket->screenshot_path) }}"
                             alt="Capture d'écran agrandie"
                             class="max-w-full max-h-full rounded-xl shadow-2xl">
                    </div>
                </div>
            @endif

            {{-- ── Commentaires ────────────────────────────────────── --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <h2 class="text-sm font-semibold text-gray-700 mb-4">Commentaires</h2>

                @if(session('comment_added'))
                    <div class="mb-4 px-4 py-2 bg-emerald-50 border border-emerald-100 rounded-lg text-sm text-emerald-700">
                        Commentaire ajouté.
                    </div>
                @endif

                {{-- Liste des commentaires --}}
                @if($ticket->comments->isEmpty())
                    <p class="text-sm text-gray-400 mb-6">Aucun commentaire pour le moment.</p>
                @else
                    <div class="space-y-4 mb-6">
                        @foreach($ticket->comments as $comment)
                            <div class="flex gap-3">
                                <div class="shrink-0 w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center">
                                    <span class="text-xs font-bold text-indigo-600">
                                        {{ strtoupper(substr($comment->user?->name ?? '?', 0, 1)) }}
                                    </span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-sm font-semibold text-gray-800">{{ $comment->user?->name ?? '—' }}</span>
                                        <span class="text-xs text-gray-400">
                                            {{ $comment->created_at->setTimezone('Europe/Paris')->translatedFormat('d F Y à H:i') }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-sm text-gray-700 whitespace-pre-wrap">{{ $comment->content }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Formulaire ajout commentaire --}}
                <form method="POST" action="{{ route('support.ticket-comment', $ticket->id) }}"
                      x-data="{ comment: '{{ old('content') }}' }">
                    @csrf
                    <div>
                        <label for="comment_content" class="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-2">
                            Ajouter un commentaire
                        </label>
                        <textarea id="comment_content"
                                  name="content"
                                  rows="3"
                                  placeholder="Votre commentaire..."
                                  x-model="comment"
                                  class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm text-gray-800 placeholder-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent resize-none">{{ old('content') }}</textarea>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="submit"
                                :disabled="comment.trim() === ''"
                                :class="comment.trim() === '' ? 'opacity-40 cursor-not-allowed' : 'hover:bg-indigo-700'"
                                class="inline-flex items-center gap-2 px-5 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-xl transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                            </svg>
                            Envoyer
                        </button>
                    </div>
                </form>
            </div>

            {{-- ── Bouton retour ───────────────────────────────────── --}}
            <div class="pb-4">
                <a href="{{ route('support.my-tickets') }}"
                   class="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                    </svg>
                    Retour à mes tickets
                </a>
            </div>

        </div>
    </div>
</x-app-layout>
