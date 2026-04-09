@php
    use Illuminate\Support\Facades\Storage;
@endphp
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
                                'resolved'=> ['label' => 'Résolu',     'class' => 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200', 'dot' => 'bg-emerald-500'],
                                'closed'  => ['label' => 'Fermé',      'class' => 'bg-gray-100 text-gray-600 ring-1 ring-gray-200',          'dot' => 'bg-gray-500'],
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

            {{-- ── Conversation ────────────────────────────────────── --}}
            @php
                $glpiStatusConfig = null;
                $resolutionDate   = null;
                $glpiUrl          = $ticket->glpi_ticket_id
                    ? str_replace('/apirest.php', '', rtrim($ticket->organization?->glpi_api_url ?? '', '/'))
                      . '/front/ticket.form.php?id=' . $ticket->glpi_ticket_id
                    : null;

                if ($glpiStatus !== null) {
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
                }

                // Fusion followups GLPI + commentaires Zeno en ordre chronologique
                $messages = [];

                if ($glpiStatus !== null && ! empty($glpiStatus['followups'])) {
                    foreach ($glpiStatus['followups'] as $fu) {
                        $hasDate = ! empty($fu['date']) && $fu['date'] !== '0000-00-00 00:00:00';
                        $fuTs    = 0;
                        $fuDate  = null;
                        if ($hasDate) {
                            try {
                                $parsed  = \Illuminate\Support\Carbon::parse($fu['date'], 'UTC');
                                $fuTs    = $parsed->timestamp;
                                $fuDate  = $parsed->setTimezone('Europe/Paris');
                            } catch (\Throwable) {}
                        }
                        $messages[] = [
                            'type'    => 'tech',
                            'author'  => $fu['author'] ?? null,
                            'user_id' => null,
                            'content' => $fu['content'],
                            'date'    => $fuDate,
                            'ts'      => $fuTs,
                        ];
                    }
                }

                foreach ($ticket->comments as $comment) {
                    $messages[] = [
                        'type'       => 'commercial',
                        'author'     => $comment->user?->name ?? '—',
                        'user_id'    => $comment->user_id,
                        'content'    => $comment->content,
                        'attachment' => $comment->attachment,
                        'date'       => $comment->created_at->setTimezone('Europe/Paris'),
                        'ts'         => $comment->created_at->timestamp,
                    ];
                }

                // Tri chronologique strict (les deux sources ont des ts en UTC)
                usort($messages, function ($a, $b) {
                    return ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0);
                });
            @endphp

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100">

                {{-- En-tête --}}
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-b border-gray-100">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-700">Conversation</h2>
                        @if($glpiStatus['assigned_to'] ?? null)
                            <p class="text-xs text-gray-400 mt-0.5">Technicien : {{ $glpiStatus['assigned_to'] }}</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-3 flex-wrap">
                        @if($glpiStatusConfig)
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium {{ $glpiStatusConfig['class'] }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $glpiStatusConfig['dot'] }}"></span>
                                {{ $glpiStatus['status_label'] }}
                            </span>
                        @endif
                        @if($resolutionDate)
                            <span class="text-xs text-gray-400">
                                Résolu le {{ $resolutionDate->translatedFormat('d F Y à H:i') }}
                            </span>
                        @endif
                        @if($glpiUrl)
                            <a href="{{ $glpiUrl }}" target="_blank"
                               class="text-xs text-gray-400 hover:text-gray-600 underline underline-offset-2 transition-colors">
                                Voir dans GLPI
                            </a>
                        @endif
                    </div>
                </div>

                {{-- Alerte GLPI indisponible --}}
                @if($ticket->glpi_ticket_id && $glpiStatus === null)
                    <div class="mx-6 mt-4 flex items-center gap-2 px-3 py-2 bg-amber-50 border border-amber-100 rounded-lg text-xs text-amber-600">
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                        Suivi GLPI temporairement indisponible — les réponses du support ne s'affichent pas.
                    </div>
                @endif

                {{-- Zone messages --}}
                <div id="chat-messages"
                     class="px-6 py-4 space-y-4"
                     style="max-height:500px;overflow-y:auto"
                     x-init="$el.scrollTop = $el.scrollHeight">
                    @if(empty($messages))
                        <p class="text-sm text-gray-400 text-center py-8">Aucun message pour le moment.</p>
                    @else
                        @foreach($messages as $msg)
                            @php
                                $isCommercial = $msg['type'] === 'commercial';
                                $isMe         = $isCommercial && $msg['user_id'] === auth()->id();
                                $authorLabel  = $isMe ? 'Vous' : ($msg['author'] ?? ($isCommercial ? '—' : 'Technicien'));
                                $initial      = strtoupper(mb_substr($msg['author'] ?? ($isCommercial ? '?' : 'T'), 0, 1));
                            @endphp
                            <div class="flex {{ $isCommercial ? 'flex-row-reverse' : 'flex-row' }} items-end gap-2">
                                <div class="shrink-0 w-8 h-8 rounded-full flex items-center justify-center
                                            {{ $isCommercial ? 'bg-blue-600' : 'bg-gray-200' }}">
                                    <span class="text-xs font-bold {{ $isCommercial ? 'text-white' : 'text-gray-500' }}">
                                        {{ $initial }}
                                    </span>
                                </div>
                                <div class="max-w-[75%]">
                                    <div class="flex {{ $isCommercial ? 'flex-row-reverse' : 'flex-row' }} items-baseline gap-2 mb-1">
                                        <span class="text-xs font-semibold {{ $isCommercial ? 'text-blue-600' : 'text-gray-500' }}">
                                            {{ $authorLabel }}
                                        </span>
                                        @if($msg['date'])
                                            <span class="text-xs text-gray-400">
                                                {{ $msg['date']->translatedFormat('d F Y à H:i') }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap
                                                {{ $isCommercial
                                                    ? 'bg-blue-600 text-white rounded-br-sm'
                                                    : 'bg-gray-100 text-gray-800 rounded-bl-sm' }}">
                                        @if(! empty($msg['content'])){{ $msg['content'] }}@endif
                                        {{-- Pièce jointe du commentaire --}}
                                        @if($msg['attachment'] ?? null)
                                            @php $att = $msg['attachment']; @endphp
                                            @if($att->isImage())
                                                <a href="{{ route('support.ticket-attachment', [$ticket->id, $att->id]) }}"
                                                   target="_blank" class="block {{ !empty($msg['content']) ? 'mt-2' : '' }}">
                                                    <img src="{{ route('support.ticket-attachment', [$ticket->id, $att->id]) }}"
                                                         alt="{{ e($att->original_name) }}"
                                                         class="max-w-[200px] rounded-xl {{ $isCommercial ? 'border border-white/20' : 'border border-gray-200' }}">
                                                </a>
                                            @else
                                                <a href="{{ route('support.ticket-attachment', [$ticket->id, $att->id]) }}"
                                                   class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs {{ !empty($msg['content']) ? 'mt-2' : '' }}
                                                          {{ $isCommercial ? 'bg-white/20 text-white' : 'bg-white text-gray-600 border border-gray-200' }}">
                                                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                                                    </svg>
                                                    <span class="truncate max-w-[140px]">{{ $att->original_name }}</span>
                                                </a>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>

                {{-- Flash commentaire ajouté --}}
                @if(session('comment_added'))
                    <div class="mx-6 px-4 py-2 bg-emerald-50 border border-emerald-100 rounded-lg text-sm text-emerald-700">
                        Commentaire ajouté.
                    </div>
                @endif

                {{-- Zone de saisie --}}
                <div class="border-t border-gray-100 px-6 py-4">
                    <form method="POST"
                          action="{{ route('support.ticket-comment', $ticket->id) }}"
                          enctype="multipart/form-data"
                          x-data="{ msg: '', fileName: '' }"
                          @submit="fileName = ''">
                        @csrf

                        {{-- Badge fichier sélectionné --}}
                        <div x-show="fileName !== ''" class="mb-2 flex items-center gap-2 px-3 py-1.5 bg-blue-50 border border-blue-100 rounded-lg text-xs text-blue-700">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                            </svg>
                            <span x-text="fileName" class="truncate max-w-[260px]"></span>
                            <button type="button" @click="fileName = ''; $refs.fileInput.value = ''"
                                    class="ml-auto shrink-0 text-blue-400 hover:text-blue-600">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div class="flex items-end gap-2">
                            {{-- Input file caché --}}
                            <input type="file"
                                   name="attachment"
                                   id="msg_file"
                                   x-ref="fileInput"
                                   accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.csv,.txt,.log"
                                   class="hidden"
                                   @change="fileName = $event.target.files[0]?.name ?? ''">

                            {{-- Bouton trombone --}}
                            <button type="button"
                                    @click="$refs.fileInput.click()"
                                    class="shrink-0 w-10 h-10 rounded-full flex items-center justify-center text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition-colors">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                                </svg>
                            </button>

                            <textarea name="content"
                                      rows="2"
                                      placeholder="Répondre au support..."
                                      x-model="msg"
                                      class="flex-1 rounded-2xl border border-gray-200 px-4 py-2.5 text-sm text-gray-800 placeholder-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent resize-none"></textarea>

                            {{-- Bouton envoyer --}}
                            <button type="submit"
                                    :disabled="msg.trim() === '' && fileName === ''"
                                    :class="msg.trim() === '' && fileName === '' ? 'opacity-40 cursor-not-allowed' : 'hover:bg-blue-700'"
                                    class="shrink-0 w-10 h-10 rounded-full bg-blue-600 flex items-center justify-center text-white transition-colors">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                                </svg>
                            </button>
                        </div>
                    </form>
                </div>

            </div>

            {{-- ── Description originale ──────────────────────────── --}}
            @if($ticket->raw_description)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h2 class="text-sm font-semibold text-gray-700 mb-1">Description originale</h2>
                    <p class="text-xs text-gray-400 mb-4">Texte saisi par le commercial, avant traitement IA</p>
                    <div class="bg-gray-50 rounded-xl p-4 text-sm text-gray-600 leading-relaxed whitespace-pre-wrap border border-gray-100">{{ $ticket->raw_description }}</div>
                </div>
            @endif

            {{-- ── Pièces jointes ──────────────────────────────────── --}}
            @if($ticket->attachments->isNotEmpty() || $ticket->screenshot_path)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6"
                     x-data="{ lightboxSrc: null }">

                    <h2 class="text-sm font-semibold text-gray-700 mb-4">
                        Pièces jointes
                        @php $attachCount = $ticket->attachments->count() + ($ticket->screenshot_path ? 1 : 0); @endphp
                        <span class="ml-1 text-xs font-normal text-gray-400">({{ $attachCount }})</span>
                    </h2>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">

                        {{-- Nouvelles pièces jointes (système sécurisé) --}}
                        @foreach($ticket->attachments as $attachment)
                            @if($attachment->isImage())
                                {{-- Image → miniature cliquable + lightbox --}}
                                <button type="button"
                                        @click="lightboxSrc = '{{ route('support.ticket-attachment', [$ticket->id, $attachment->id]) }}'"
                                        class="group relative aspect-video overflow-hidden rounded-xl border border-gray-100
                                               hover:border-blue-300 transition-colors bg-gray-50 cursor-zoom-in">
                                    <img src="{{ route('support.ticket-attachment', [$ticket->id, $attachment->id]) }}"
                                         alt="{{ e($attachment->original_name) }}"
                                         class="w-full h-full object-cover group-hover:opacity-90 transition-opacity">
                                    <div class="absolute inset-x-0 bottom-0 px-2 py-1 bg-gradient-to-t from-black/50
                                                opacity-0 group-hover:opacity-100 transition-opacity rounded-b-xl">
                                        <p class="text-white text-xs truncate">{{ $attachment->original_name }}</p>
                                    </div>
                                </button>
                            @else
                                {{-- Fichier non-image → téléchargement --}}
                                @php
                                    $fileIcon = match(true) {
                                        $attachment->mime_type === 'application/pdf'
                                            => ['color' => 'text-red-500',     'label' => 'PDF'],
                                        in_array($attachment->mime_type, ['text/csv', 'application/vnd.ms-excel'])
                                            => ['color' => 'text-emerald-600', 'label' => 'CSV'],
                                        default
                                            => ['color' => 'text-gray-500',    'label' => 'FILE'],
                                    };
                                @endphp
                                <a href="{{ route('support.ticket-attachment', [$ticket->id, $attachment->id]) }}"
                                   class="flex items-center gap-3 p-3 rounded-xl border border-gray-100
                                          hover:border-blue-300 hover:bg-blue-50/30 transition-colors">
                                    <div class="shrink-0">
                                        <svg class="w-8 h-8 {{ $fileIcon['color'] }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-700 truncate">{{ $attachment->original_name }}</p>
                                        <p class="text-xs text-gray-400">
                                            {{ $fileIcon['label'] }} · {{ number_format($attachment->size / 1024, 0, ',', ' ') }} Ko
                                        </p>
                                    </div>
                                    <svg class="w-4 h-4 text-gray-300 shrink-0 ml-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                    </svg>
                                </a>
                            @endif
                        @endforeach

                        {{-- Capture d'écran legacy (ancien système public) --}}
                        @if($ticket->screenshot_path)
                            <button type="button"
                                    @click="lightboxSrc = '{{ Storage::url($ticket->screenshot_path) }}'"
                                    class="group relative aspect-video overflow-hidden rounded-xl border border-gray-100
                                           hover:border-blue-300 transition-colors bg-gray-50 cursor-zoom-in">
                                <img src="{{ Storage::url($ticket->screenshot_path) }}"
                                     alt="Capture d'écran"
                                     class="w-full h-full object-cover group-hover:opacity-90 transition-opacity">
                                <div class="absolute top-1.5 right-1.5 px-1.5 py-0.5 bg-black/40 text-white
                                            text-xs rounded-md leading-none">legacy</div>
                            </button>
                        @endif

                    </div>

                    {{-- Lightbox --}}
                    <div x-show="lightboxSrc"
                         x-transition.opacity
                         @click.self="lightboxSrc = null"
                         @keydown.escape.window="lightboxSrc = null"
                         class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
                         style="display:none">
                        <button @click="lightboxSrc = null"
                                class="absolute top-4 right-4 text-white/80 hover:text-white text-3xl leading-none">&times;</button>
                        <img :src="lightboxSrc" class="max-w-full max-h-full rounded-xl shadow-2xl">
                    </div>
                </div>
            @endif


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
