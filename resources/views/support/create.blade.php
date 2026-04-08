{{-- resources/views/support/create.blade.php --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Signaler un problème client
        </h2>
    </x-slot>

<div class="min-h-screen bg-gray-50 py-8 px-4" x-data="supportForm()" x-cloak>
    <div class="max-w-lg mx-auto">

        {{-- Header --}}
        <div class="flex items-center gap-3 mb-6 bg-white rounded-xl p-4 shadow-sm">
            <div class="w-10 h-10 rounded-lg bg-blue-600 flex items-center justify-center text-white font-bold text-lg">
                S
            </div>
            <div>
                <h1 class="font-bold text-gray-900">SupportIA</h1>
                <p class="text-xs text-gray-500">Signalement assisté par IA</p>
            </div>
            <template x-if="result && result.provider">
                <span class="ml-auto text-xs px-2 py-1 rounded-md font-semibold"
                      :class="result.provider === 'claude'
                          ? 'bg-blue-50 text-blue-700'
                          : 'bg-amber-50 text-amber-700'"
                      x-text="(result.provider === 'claude' ? 'Claude' : 'Fallback')
                              + ' · ' + latency + 's'">
                </span>
            </template>
        </div>

        {{-- ═══════════ FORMULAIRE ═══════════ --}}
        <template x-if="state === 'form'">
            <div class="bg-white rounded-xl p-6 shadow-sm">
                <h2 class="font-bold text-gray-900 mb-5">Signaler un problème client</h2>

                {{-- Client --}}
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-600 mb-1">Client</label>
                    <input type="text"
                           x-model="clientName"
                           placeholder="Nom du client (optionnel)"
                           class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm
                                  focus:ring-2 focus:ring-blue-500 focus:border-blue-500
                                  outline-none transition-colors" />
                </div>

                {{-- Description --}}
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-600 mb-1">
                        Décrivez le problème
                    </label>
                    <textarea x-model="description"
                              x-ref="textarea"
                              rows="5"
                              placeholder="Ex : Le client n'arrive plus à se connecter depuis ce matin, ses annonces ne remontent plus sur LBC..."
                              class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm
                                     leading-relaxed focus:ring-2 focus:ring-blue-500
                                     focus:border-blue-500 outline-none transition-colors resize-y"
                    ></textarea>
                    <div class="flex justify-between text-xs text-gray-400 mt-1">
                        <span x-show="description.length > 0 && description.length < 10"
                              class="text-amber-500">
                            ⚠ Minimum 10 caractères
                        </span>
                        <span x-show="description.length === 0 || description.length >= 10">&nbsp;</span>
                        <span x-text="description.length + ' car.'"></span>
                    </div>
                </div>

                {{-- Capture d'écran --}}
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-600 mb-1">
                        Capture d'écran <span class="font-normal text-gray-400">(optionnel)</span>
                    </label>
                    <div class="relative border-2 border-dashed rounded-lg transition-colors cursor-pointer"
                         :class="screenshotPreview ? 'border-blue-300 bg-blue-50/30' : 'border-gray-200 hover:border-blue-300'"
                         @click="$refs.fileInput.click()"
                         @dragover.prevent="$event.currentTarget.classList.add('border-blue-400','bg-blue-50')"
                         @dragleave.prevent="$event.currentTarget.classList.remove('border-blue-400','bg-blue-50')"
                         @drop.prevent="$event.currentTarget.classList.remove('border-blue-400','bg-blue-50'); handleScreenshot($event)">
                        <input type="file" x-ref="fileInput" accept="image/*" class="hidden"
                               @change="handleScreenshot($event)">
                        <template x-if="!screenshotPreview">
                            <div class="p-4 text-center">
                                <svg class="w-7 h-7 text-gray-300 mx-auto mb-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                                </svg>
                                <p class="text-sm text-gray-400">Cliquez ou déposez une image</p>
                                <p class="text-xs text-gray-300 mt-0.5">PNG, JPG, GIF — max 5 MB</p>
                            </div>
                        </template>
                        <template x-if="screenshotPreview">
                            <div class="p-3 flex items-center gap-3">
                                <img :src="screenshotPreview" class="h-16 w-16 object-cover rounded-lg border border-gray-200 shrink-0">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-700 truncate" x-text="screenshot?.name"></p>
                                    <p class="text-xs text-gray-400 mt-0.5" x-text="screenshot ? Math.round(screenshot.size / 1024) + ' KB' : ''"></p>
                                </div>
                                <button type="button" @click.stop="screenshot = null; screenshotPreview = null; $refs.fileInput.value = ''"
                                        class="shrink-0 text-gray-400 hover:text-red-500 transition-colors p-1">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Erreur --}}
                <template x-if="error">
                    <div class="bg-red-50 text-red-600 text-sm font-medium px-4 py-3 rounded-lg mb-4"
                         x-text="error"></div>
                </template>

                {{-- Bouton --}}
                <button @click="submit()"
                        :disabled="description.trim().length < 10"
                        class="w-full py-3 rounded-lg font-bold text-white transition-all text-sm"
                        :class="description.trim().length >= 10
                            ? 'bg-blue-600 hover:bg-blue-700 cursor-pointer active:scale-[0.98]'
                            : 'bg-gray-300 cursor-not-allowed'">
                    Envoyer au support
                </button>
            </div>
        </template>

        {{-- ═══════════ CHARGEMENT ═══════════ --}}
        <template x-if="state === 'loading'">
            <div class="bg-white rounded-xl p-12 shadow-sm text-center">
                <div class="w-10 h-10 border-[3px] border-gray-200 border-t-blue-600
                            rounded-full animate-spin mx-auto mb-4"></div>
                <p class="font-semibold text-gray-900">Analyse en cours...</p>
                <p class="text-sm text-gray-500 mt-1">L'IA classifie et structure votre demande</p>
            </div>
        </template>

        {{-- ═══════════ RÉSULTAT (confiance haute) ═══════════ --}}
        <template x-if="state === 'result'">
            <div class="bg-white rounded-xl p-6 shadow-sm">
                <div class="flex items-center gap-3 bg-green-50 rounded-lg p-3 mb-5">
                    <span class="text-xl">✅</span>
                    <div>
                        <p class="font-bold text-green-800 text-sm">Ticket prêt à être créé</p>
                        <p class="text-xs text-green-600">
                            Confiance IA :
                            <span x-text="Math.round(result.confidence * 100) + '%'"></span>
                        </p>
                    </div>
                </div>

                <div class="mb-3">
                    <span class="text-xs font-semibold text-gray-400 uppercase">Titre</span>
                    <p class="font-semibold text-gray-900 mt-1" x-text="result.title"></p>
                </div>

                <div class="flex gap-3 mb-3">
                    <div class="flex-1">
                        <span class="text-xs font-semibold text-gray-400 uppercase">Catégorie</span>
                        <p class="mt-1 text-sm font-semibold text-blue-700 bg-blue-50
                                  inline-block px-2 py-1 rounded-md"
                           x-text="getCategoryLabel(result.category_slug)"></p>
                    </div>
                    <div>
                        <span class="text-xs font-semibold text-gray-400 uppercase">Priorité</span>
                        <p class="mt-1 text-sm font-semibold px-2 py-1 rounded-md"
                           :class="priorityClass(result.priority)"
                           x-text="priorityEmoji(result.priority) + ' ' + priorityLabel(result.priority)">
                        </p>
                    </div>
                </div>

                <div class="mb-5">
                    <span class="text-xs font-semibold text-gray-400 uppercase">Description structurée</span>
                    <div class="mt-1 bg-gray-50 border border-gray-100 rounded-lg p-3
                                text-sm text-gray-700 leading-relaxed whitespace-pre-wrap"
                         x-text="result.body"></div>
                </div>

                <div class="flex gap-3">
                    <button @click="confirmResult()"
                            class="flex-1 py-3 rounded-lg font-bold text-white text-sm
                                   bg-green-600 hover:bg-green-700 active:scale-[0.98] transition-all">
                        Créer le ticket GLPI
                    </button>
                    <button @click="state = 'review'; editResult = JSON.parse(JSON.stringify(result))"
                            class="px-4 py-3 rounded-lg font-semibold text-gray-600 text-sm
                                   bg-gray-100 hover:bg-gray-200 transition-colors">
                        Modifier
                    </button>
                </div>
            </div>
        </template>

        {{-- ═══════════ REVIEW (confiance basse ou édition) ═══════════ --}}
        <template x-if="state === 'review'">
            <div class="bg-white rounded-xl p-6 shadow-sm">
                <div class="flex items-center gap-3 rounded-lg p-3 mb-5"
                     :class="result.confidence < 0.7 ? 'bg-amber-50' : 'bg-blue-50'">
                    <span class="text-xl"
                          x-text="result.confidence < 0.7 ? '⚠️' : '✏️'"></span>
                    <div>
                        <p class="font-bold text-sm"
                           :class="result.confidence < 0.7 ? 'text-amber-800' : 'text-blue-800'"
                           x-text="result.confidence < 0.7
                               ? 'Vérification nécessaire'
                               : 'Modifier le ticket'"></p>
                        <p class="text-xs text-gray-500"
                           x-text="result.confidence < 0.7
                               ? 'Confiance IA : ' + Math.round(result.confidence * 100) + '% — vérifiez avant envoi'
                               : 'Ajustez les champs si nécessaire'"></p>
                    </div>
                </div>

                {{-- Titre --}}
                <div class="mb-3">
                    <label class="block text-xs font-semibold text-gray-400 uppercase mb-1">Titre</label>
                    <input type="text" x-model="editResult.title"
                           class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm
                                  font-semibold focus:ring-2 focus:ring-blue-500
                                  focus:border-blue-500 outline-none" />
                </div>

                {{-- Catégorie --}}
                <div class="mb-3">
                    <label class="block text-xs font-semibold text-gray-400 uppercase mb-1">Catégorie</label>
                    <select x-model="editResult.category_slug"
                            class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm
                                   focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                        <template x-for="cat in categories.filter(c => c.is_visible_to_users)" :key="cat.slug">
                            <option :value="cat.slug" x-text="cat.label_simple || cat.label"></option>
                        </template>
                    </select>
                </div>

                {{-- Priorité --}}
                <div class="mb-3">
                    <label class="block text-xs font-semibold text-gray-400 uppercase mb-2">Priorité</label>
                    <div class="flex gap-2">
                        <template x-for="p in [1,2,3,4,5]" :key="p">
                            <button @click="editResult.priority = p"
                                    class="flex-1 py-2 rounded-lg text-xs font-semibold border-2 transition-all"
                                    :class="editResult.priority === p
                                        ? priorityClass(p) + ' border-current'
                                        : 'border-gray-200 text-gray-400 hover:border-gray-300'"
                                    x-text="priorityLabel(p)">
                            </button>
                        </template>
                    </div>
                </div>

                {{-- Corps --}}
                <div class="mb-5">
                    <label class="block text-xs font-semibold text-gray-400 uppercase mb-1">Description</label>
                    <textarea x-model="editResult.body" rows="6"
                              class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm
                                     leading-relaxed focus:ring-2 focus:ring-blue-500
                                     focus:border-blue-500 outline-none resize-y"></textarea>
                </div>

                <div class="flex gap-3">
                    <button @click="confirmEdited()"
                            class="flex-1 py-3 rounded-lg font-bold text-white text-sm
                                   bg-green-600 hover:bg-green-700 active:scale-[0.98] transition-all">
                        Confirmer et créer
                    </button>
                    <button @click="state = 'form'"
                            class="px-4 py-3 rounded-lg font-semibold text-gray-600 text-sm
                                   bg-gray-100 hover:bg-gray-200 transition-colors">
                        Annuler
                    </button>
                </div>
            </div>
        </template>

        {{-- ═══════════ TICKET CRÉÉ ═══════════ --}}
        <template x-if="state === 'created'">
            <div class="bg-white rounded-xl p-6 shadow-sm text-center">
                <div class="w-14 h-14 rounded-full bg-green-600 flex items-center justify-center
                            text-white text-2xl mx-auto mb-4">✓</div>

                <h2 class="text-lg font-bold text-green-700 mb-1">
                    Ticket
                    <span x-text="glpiTicketId ? '#' + glpiTicketId : ''"></span>
                    <span x-text="glpiTicketId ? 'créé' : 'enregistré'"></span>
                </h2>
                <p class="text-sm text-gray-500 mb-5"
                   x-text="glpiTicketId
                       ? 'L\'équipe support a été notifiée'
                       : 'La création GLPI sera retentée automatiquement'">
                </p>

                <div class="text-left bg-gray-50 rounded-lg p-4 mb-5 text-sm space-y-2">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Titre</span>
                        <span class="font-semibold text-right max-w-[60%]"
                              x-text="createdData.title"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Catégorie</span>
                        <span class="font-semibold text-blue-600"
                              x-text="getCategoryLabel(createdData.category_slug)"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Priorité</span>
                        <span class="font-semibold"
                              :class="priorityClass(createdData.priority)"
                              x-text="priorityEmoji(createdData.priority) + ' '
                                      + priorityLabel(createdData.priority)"></span>
                    </div>
                    <template x-if="clientName">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Client</span>
                            <span class="font-semibold" x-text="clientName"></span>
                        </div>
                    </template>
                </div>

                {{-- Estimation temps de traitement --}}
                <div class="mb-5 rounded-lg p-4 text-sm"
                     :class="createdData.estimate_hours !== null && createdData.estimate_hours !== undefined
                         ? 'bg-indigo-50'
                         : 'bg-gray-50'">
                    <template x-if="createdData.estimate_hours !== null && createdData.estimate_hours !== undefined">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-indigo-400 mb-1">
                                Temps de traitement estimé
                            </p>
                            <p class="text-lg font-bold text-indigo-700"
                               x-text="estimateDisplay(createdData.estimate_hours)"></p>
                            <p class="text-xs text-indigo-400 mt-0.5"
                               x-text="'Basé sur les ' + createdData.estimate_count + ' tickets précédents de cette catégorie'"></p>
                        </div>
                    </template>
                    <template x-if="createdData.estimate_hours === null || createdData.estimate_hours === undefined">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1">
                                Temps de traitement estimé
                            </p>
                            <p class="text-sm text-gray-400">Estimation non disponible — pas assez d'historique</p>
                        </div>
                    </template>
                </div>

                <template x-if="createdData.ticket_id">
                    <a :href="`/support/tickets/${createdData.ticket_id}`"
                       class="block w-full py-3 rounded-lg font-bold text-indigo-600
                              bg-indigo-50 hover:bg-indigo-100 text-sm mb-3 transition-colors">
                        Voir le détail du ticket →
                    </a>
                </template>

                <button @click="reset()"
                        class="w-full py-3 rounded-lg font-bold text-white text-sm
                               bg-blue-600 hover:bg-blue-700 active:scale-[0.98] transition-all">
                    Nouveau signalement
                </button>
            </div>
        </template>

    </div>
</div>

<script>
function supportForm() {
    return {
        // State machine
        state: 'form', // form | loading | result | review | created

        // Inputs
        description: '',
        clientName: '',
        screenshot: null,
        screenshotPreview: null,
        error: null,

        // IA result
        result: null,
        editResult: null,
        latency: 0,

        // GLPI result
        glpiTicketId: null,
        glpiUrl: null,
        createdData: {},

        // Catégories (injectées par le controller Blade)
        categories: @json($categories ?? []),

        // ─── Init (pré-remplissage via query params) ─────────────
        init() {
            const params = new URLSearchParams(window.location.search);
            if (params.get('description')) this.description = params.get('description');
            if (params.get('client_name')) this.clientName  = params.get('client_name');
        },

        // ─── Actions ────────────────────────

        handleScreenshot(event) {
            const file = event.target?.files?.[0] ?? event.dataTransfer?.files?.[0];
            if (!file) return;
            if (!file.type.startsWith('image/')) {
                this.error = 'Le fichier doit être une image (PNG, JPG, GIF…).';
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                this.error = 'L\'image ne doit pas dépasser 5 MB.';
                return;
            }
            this.screenshot = file;
            const reader = new FileReader();
            reader.onload = e => this.screenshotPreview = e.target.result;
            reader.readAsDataURL(file);
        },

        async submit() {
            if (this.description.trim().length < 10) {
                this.error = 'Décrivez le problème en au moins 10 caractères.';
                return;
            }
            this.error = null;
            this.state = 'loading';
            const start = Date.now();

            try {
                const formData = new FormData();
                formData.append('description', this.description);
                if (this.clientName) formData.append('client_name', this.clientName);
                if (this.screenshot) formData.append('screenshot', this.screenshot);

                const resp = await fetch('/support/tickets', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                    body: formData,
                });

                this.latency = ((Date.now() - start) / 1000).toFixed(1);
                const data = await resp.json();

                if (!resp.ok) {
                    throw new Error(data.error || data.message || 'Erreur serveur');
                }

                if (data.status === 'created') {
                    this.result = data;
                    this.createdData = data;
                    this.glpiTicketId = data.glpi_ticket_id;
                    this.glpiUrl = data.glpi_url;
                    this.state = 'created';
                } else if (data.status === 'queued') {
                    this.result = data;
                    this.createdData = data;
                    this.glpiTicketId = null;
                    this.glpiUrl = null;
                    this.state = 'created';
                } else if (data.status === 'needs_review') {
                    this.result = data.suggestion;
                    this.result.ticket_id = data.ticket_id;
                    this.editResult = JSON.parse(JSON.stringify(this.result));
                    if (data.categories) {
                        this.categories = data.categories;
                    }
                    this.state = 'review';
                }
            } catch (err) {
                this.error = err.message;
                this.state = 'form';
            }
        },

        confirmResult() {
            // Le ticket à haute confiance est déjà créé côté serveur
            this.createdData = this.result;
            this.state = 'created';
        },

        async confirmEdited() {
            this.state = 'loading';
            const ticketId = this.result?.ticket_id || this.editResult?.ticket_id;

            if (!ticketId) {
                this.error = 'ID de ticket manquant. Veuillez recommencer.';
                this.state = 'form';
                return;
            }

            try {
                const resp = await fetch(`/support/tickets/${ticketId}/confirm`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                    body: JSON.stringify(this.editResult),
                });

                const data = await resp.json();

                if (!resp.ok) {
                    throw new Error(data.error || 'Erreur lors de la création');
                }

                this.createdData = {
                    ...this.editResult,
                    ticket_id:      data.ticket_id || ticketId,
                    estimate_hours: data.estimate_hours ?? null,
                    estimate_count: data.estimate_count ?? 0,
                };
                this.glpiTicketId = data.glpi_ticket_id || null;
                this.glpiUrl = data.glpi_url || null;
                this.state = 'created';
            } catch (err) {
                this.error = err.message;
                this.state = 'form';
            }
        },

        reset() {
            this.state = 'form';
            this.description = '';
            this.clientName = '';
            this.screenshot = null;
            this.screenshotPreview = null;
            this.error = null;
            this.result = null;
            this.editResult = null;
            this.glpiTicketId = null;
            this.glpiUrl = null;
            this.createdData = {};
        },

        // ─── Helpers ────────────────────────

        estimateDisplay(hours) {
            if (hours === null || hours === undefined) return '';
            if (hours < 24) {
                const h = Math.round(hours);
                return '~' + h + ' heure' + (h > 1 ? 's' : '');
            }
            const d = Math.round(hours / 24);
            return '~' + d + ' jour' + (d > 1 ? 's' : '');
        },

        getCategoryLabel(slug) {
            const cat = this.categories.find(c => c.slug === slug);
            return cat ? (cat.label_simple || cat.label) : slug;
        },

        priorityLabel(p) {
            return { 1: 'Très basse', 2: 'Basse', 3: 'Moyenne', 4: 'Haute', 5: 'Très haute' }[p] || 'Moyenne';
        },

        priorityEmoji(p) {
            return { 1: '⚪', 2: '🔵', 3: '🟡', 4: '🔴', 5: '🔴' }[p] || '🟡';
        },

        priorityClass(p) {
            return {
                1: 'bg-gray-100 text-gray-600',
                2: 'bg-blue-50 text-blue-700',
                3: 'bg-amber-50 text-amber-700',
                4: 'bg-red-50 text-red-600',
                5: 'bg-red-100 text-red-700',
            }[p] || 'bg-gray-100 text-gray-600';
        },
    };
}
</script>

</x-app-layout>
