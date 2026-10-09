{{-- resources/views/support/create.blade.php --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Signaler un problème client
        </h2>
    </x-slot>

<div class="min-h-screen bg-gray-50 py-8 px-4" x-data="supportForm()" x-cloak>
    <div class="max-w-5xl mx-auto lg:grid lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-6 lg:items-start">
    <div class="min-w-0 max-w-lg w-full mx-auto lg:max-w-none">

        {{-- Header --}}
        <div class="flex items-center gap-3 mb-6 bg-white rounded-xl p-4 shadow-sm">
            <div class="w-10 h-10 rounded-lg bg-blue-600 flex items-center justify-center text-white font-bold text-lg">
                Z
            </div>
            <div>
                <h1 class="font-bold text-gray-900">Zeno</h1>
                <p class="text-xs text-gray-500">Signalement assisté par IA</p>
            </div>
            <template x-if="result && result.provider">
                <span class="ml-auto text-xs px-2 py-1 rounded-md font-semibold"
                      :class="@js(\App\Models\SupportTicket::AI_PROVIDERS).includes(result.provider)
                          ? 'bg-blue-50 text-blue-700'
                          : 'bg-amber-50 text-amber-700'"
                      x-text="@js(\App\Models\SupportTicket::AI_PROVIDERS).includes(result.provider)
                          ? @js(\App\Models\SupportTicket::analysisLabel('openai'))
                          : @js(\App\Models\SupportTicket::analysisLabel(null))">
                </span>
            </template>
        </div>

        {{-- ═══════════ FORMULAIRE ═══════════ --}}
        <template x-if="state === 'form'">
            <div class="bg-white rounded-xl p-6 shadow-sm">
                <h2 class="font-bold text-gray-900 mb-5">Signaler un problème client</h2>

                {{-- Client --}}
                <div class="mb-4">
                    <label class="flex items-center gap-2 cursor-pointer select-none mb-3">
                        <input type="checkbox"
                               x-model="isSpecificClient"
                               class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                        <span class="text-sm font-semibold text-gray-700">Ce problème concerne un client spécifique</span>
                    </label>

                    {{-- Champs clients dynamiques --}}
                    <div x-show="isSpecificClient" x-transition>
                        <template x-for="(client, index) in clients" :key="client.uid">
                            <div class="flex items-center gap-2 mb-2">
                                <input type="text"
                                       x-model="client.id"
                                       @input.debounce.600ms="checkDuplicates()"
                                       placeholder="ID client *"
                                       class="w-28 px-3 py-2 border border-gray-200 rounded-lg text-sm
                                              focus:ring-2 focus:ring-blue-500 focus:border-blue-500
                                              outline-none transition-colors"
                                       :class="client.id.trim() === '' && clients.length === 1 ? 'border-amber-300' : ''">
                                <input type="text"
                                       x-model="client.name"
                                       placeholder="Nom du client"
                                       class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm
                                              focus:ring-2 focus:ring-blue-500 focus:border-blue-500
                                              outline-none transition-colors">
                                <button type="button"
                                        x-show="clients.length > 1"
                                        @click="removeClient(index)"
                                        class="shrink-0 text-gray-300 hover:text-red-400 transition-colors p-1">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </template>
                        <button type="button"
                                x-show="clients.length < 10"
                                @click="addClient()"
                                class="mt-1 text-xs font-semibold text-blue-600 hover:text-blue-800
                                       flex items-center gap-1 transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Ajouter un autre client
                        </button>
                    </div>

                    {{-- Alerte doublon --}}
                    <template x-if="isSpecificClient && duplicates.length > 0">
                        <div class="mt-2 mb-1 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm">
                            <p class="font-semibold text-amber-800 mb-1">Un ticket est déjà ouvert pour ce client</p>
                            <ul class="space-y-1">
                                <template x-for="(d, i) in duplicates" :key="i">
                                    <li class="text-amber-700">
                                        <template x-if="d.visible">
                                            <span>Client <span x-text="d.clientId"></span> :
                                                <a :href="d.url" class="underline font-medium" x-text="d.title"></a>
                                                (<span x-text="d.age"></span>) — vous pouvez y ajouter un commentaire.</span>
                                        </template>
                                        <template x-if="!d.visible">
                                            <span>Client <span x-text="d.clientId"></span> : un collègue a ouvert un ticket <span x-text="d.age"></span>.</span>
                                        </template>
                                    </li>
                                </template>
                            </ul>
                            <p class="text-xs text-amber-700 mt-1">Si c'est un problème différent, continuez normalement.</p>
                        </div>
                    </template>

                    {{-- Contexte libre si pas de client spécifique --}}
                    <div x-show="!isSpecificClient" x-transition>
                        <textarea x-model="context"
                                  rows="2"
                                  placeholder="Contexte (optionnel) : interne, tous les clients, autre..."
                                  class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm
                                         focus:ring-2 focus:ring-blue-500 focus:border-blue-500
                                         outline-none transition-colors resize-none"></textarea>
                    </div>
                </div>

                {{-- Description --}}
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-600 mb-1">
                        Décrivez le problème
                    </label>
                    <textarea x-model="description"
                              x-ref="textarea"
                              @input="descriptionError = null"
                              rows="5"
                              placeholder="Ex : Le client n'arrive plus à se connecter depuis ce matin, ses annonces ne remontent plus sur LBC..."
                              class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm
                                     leading-relaxed focus:ring-2 focus:ring-blue-500
                                     focus:border-blue-500 outline-none transition-colors resize-y"
                    ></textarea>
                    <div class="flex justify-between items-start text-xs mt-1">
                        <span :class="{
                                'text-green-500': descriptionStatus === 'valid',
                                'text-red-500':   descriptionStatus === 'garbage',
                                'text-gray-400':  descriptionStatus === 'short'
                              }"
                              x-text="descriptionStatus === 'valid'
                                  ? '✓ Description valide'
                                  : descriptionStatus === 'garbage'
                                      ? 'Veuillez décrire le problème plus précisément'
                                      : 'Décrivez le problème en quelques mots (20 caractères minimum)'">
                        </span>
                        <span class="text-gray-400 shrink-0 ml-2" x-text="description.length + ' car.'"></span>
                    </div>
                    <template x-if="descriptionError">
                        <p class="mt-1 text-xs text-red-500" x-text="descriptionError"></p>
                    </template>
                    {{-- Indice de catégorie en direct : simple recherche de mots-clés, aucun appel IA --}}
                    <template x-if="liveCategory">
                        <p class="mt-2 inline-flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-full bg-blue-50 text-blue-700">
                            Catégorie probable : <strong x-text="liveCategory"></strong>
                        </p>
                    </template>
                </div>

                {{-- Pièces jointes --}}
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-600 mb-1">
                        Pièces jointes
                        <span class="font-normal text-gray-400">(optionnel · max 5 fichiers · {{ \App\Models\TicketAttachment::maxSizeLabel() }} chacun)</span>
                    </label>
                    <div class="border-2 border-dashed rounded-lg transition-colors"
                         :class="attachments.length > 0 ? 'border-blue-300 bg-blue-50/30' : 'border-gray-200 hover:border-blue-300'"
                         @dragover.prevent="$event.currentTarget.classList.add('border-blue-400','bg-blue-50')"
                         @dragleave.prevent="$event.currentTarget.classList.remove('border-blue-400','bg-blue-50')"
                         @drop.prevent="$event.currentTarget.classList.remove('border-blue-400','bg-blue-50'); addAttachments($event.dataTransfer.files)">

                        <input type="file" x-ref="fileInput"
                               accept="{{ \App\Models\TicketAttachment::acceptAttribute() }}"
                               multiple class="hidden"
                               @change="addAttachments($event.target.files); $event.target.value = ''">

                        {{-- Zone vide --}}
                        <template x-if="attachments.length === 0">
                            <div class="p-4 text-center cursor-pointer" @click="$refs.fileInput.click()">
                                <svg class="w-7 h-7 text-gray-300 mx-auto mb-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                                </svg>
                                <p class="text-sm text-gray-400">Cliquez ou déposez vos fichiers</p>
                                <p class="text-xs text-gray-300 mt-0.5">{{ ucfirst(\App\Models\TicketAttachment::formatsLabel()) }}</p>
                            </div>
                        </template>

                        {{-- Liste des fichiers sélectionnés --}}
                        <template x-if="attachments.length > 0">
                            <div class="p-3 space-y-1.5">
                                <template x-for="(att, index) in attachments" :key="index">
                                    <div class="flex items-center gap-2.5 py-1 px-1 rounded-lg hover:bg-white/60">
                                        {{-- Miniature si image, icône sinon --}}
                                        <template x-if="att.preview">
                                            <img :src="att.preview"
                                                 class="w-9 h-9 object-cover rounded border border-gray-200 shrink-0">
                                        </template>
                                        <template x-if="!att.preview">
                                            <div class="w-9 h-9 flex items-center justify-center bg-gray-100 rounded shrink-0">
                                                <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                                </svg>
                                            </div>
                                        </template>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm text-gray-700 truncate" x-text="att.name"></p>
                                            <p class="text-xs text-gray-400" x-text="formatFileSize(att.size)"></p>
                                        </div>
                                        <button type="button" @click="removeAttachment(index)"
                                                class="shrink-0 text-gray-300 hover:text-red-400 transition-colors p-1">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </template>
                                {{-- Ajouter d'autres fichiers si < 5 --}}
                                <template x-if="attachments.length < 5">
                                    <button type="button" @click="$refs.fileInput.click()"
                                            class="w-full mt-1 py-1.5 text-xs font-semibold text-blue-600
                                                   hover:text-blue-800 transition-colors text-center">
                                        + Ajouter un fichier
                                    </button>
                                </template>
                                <template x-if="attachments.length >= 5">
                                    <p class="text-xs text-center text-gray-400 mt-1">Maximum de 5 fichiers atteint</p>
                                </template>
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
                        :disabled="!canSubmit"
                        class="w-full py-3 rounded-lg font-bold text-white transition-all text-sm"
                        :class="canSubmit
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
                               ? 'Vérifiez la catégorie et la description avant l\'envoi'
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
                        <template x-for="cat in categories.filter(c => c.is_visible_to_users || c.slug === editResult.category_slug)" :key="cat.slug">
                            <option :value="cat.slug" :selected="cat.slug === editResult.category_slug"
                                    x-text="cat.label_simple || cat.label"></option>
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
                    <button @click="cancelDraft()"
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
                       : 'Le support est momentanément injoignable : l\'envoi sera relancé automatiquement'">
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
                    <template x-if="isSpecificClient && clients.filter(c => c.id.trim()).length > 0">
                        <div class="flex justify-between">
                            <span class="text-gray-500 shrink-0">Client(s)</span>
                            <span class="font-semibold text-right"
                                  x-text="clients.filter(c => c.id.trim()).map(c => c.id + (c.name ? ' ' + c.name : '')).join(', ')"></span>
                        </div>
                    </template>
                </div>


                <template x-if="createdData.estimate_hours">
                    <div class="mb-5 rounded-lg p-4 text-sm bg-indigo-50 text-left">
                        <p class="text-xs font-semibold uppercase tracking-wider text-indigo-400 mb-1">Délai de traitement habituel</p>
                        <p class="text-lg font-bold text-indigo-700" x-text="estimateDisplay(createdData.estimate_hours)"></p>
                        <p class="text-xs text-indigo-400 mt-0.5"
                           x-text="'Médiane des ' + createdData.estimate_count + ' derniers tickets résolus de cette catégorie'"></p>
                    </div>
                </template>

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

    {{-- ═══════════ CONSEILS (colonne de droite) ═══════════ --}}
    <aside x-show="state === 'form'" class="mt-6 lg:mt-0 max-w-lg w-full mx-auto lg:max-w-none lg:sticky lg:top-6">
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
            <h3 class="font-bold text-gray-900 mb-3">Pour un bon ticket</h3>
            <ul class="space-y-3 text-sm text-gray-600">
                <li class="flex gap-2.5"><span class="zeno-tip-dot">1</span><span><strong class="text-gray-800">L'ID client</strong> : le support retrouve le compte tout de suite.</span></li>
                <li class="flex gap-2.5"><span class="zeno-tip-dot">2</span><span><strong class="text-gray-800">Ce que voit le client</strong> : message d'erreur, écran, annonce concernée.</span></li>
                <li class="flex gap-2.5"><span class="zeno-tip-dot">3</span><span><strong class="text-gray-800">Depuis quand</strong> et si d'autres clients sont touchés.</span></li>
                <li class="flex gap-2.5"><span class="zeno-tip-dot">4</span><span><strong class="text-gray-800">Une capture d'écran</strong> vaut mieux qu'un long texte.</span></li>
            </ul>
        </div>
    </aside>
    </div>
</div>

<script>
function supportForm() {
    return {
        // State machine
        state: 'form', // form | loading | result | review | created

        // Inputs
        description: '',
        isSpecificClient: true,
        clients: [{ uid: 1, id: '', name: '' }],
        duplicates: [],
        context: '',
        attachments: [],    // [{file, name, size, preview}]
        error: null,
        descriptionError: null,

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
            if (params.get('client_name')) this.clients[0].name = params.get('client_name');
        },

        // ─── Gestion des clients ─────────────────────────────────
        // ─── Doublons : tickets encore ouverts pour ces clients ──
        async checkDuplicates() {
            const ids = [...new Set(this.clients.map(c => c.id.trim()).filter(Boolean))];
            const found = [];
            for (const id of ids) {
                try {
                    const resp = await fetch(`/support/tickets/open-for-client?client_id=${encodeURIComponent(id)}`, { headers: { 'Accept': 'application/json' } });
                    if (!resp.ok) continue;
                    const data = await resp.json();
                    data.tickets.forEach(t => found.push({ ...t, clientId: id }));
                } catch (e) { /* non bloquant */ }
            }
            this.duplicates = found;
        },

        addClient() {
            if (this.clients.length < 10) this.clients.push({ uid: Date.now(), id: '', name: '' });
        },

        removeClient(index) {
            this.clients.splice(index, 1);
        },

        // Indice de catégorie pendant la saisie (mêmes mots-clés que l'analyse de secours, côté navigateur)
        get liveCategory() {
            const text = this.description.toLowerCase();
            if (text.trim().length < 20) return null;
            let best = null, bestScore = 0;
            for (const cat of this.categories) {
                if (!cat.is_visible_to_users) continue;
                const score = (cat.keywords || []).filter(k => k && text.includes(String(k).toLowerCase())).length;
                if (score > bestScore) { best = cat; bestScore = score; }
            }
            return best ? (best.label_simple || best.label) : null;
        },

        get descriptionStatus() {
            if (this.description.trim().length < 20) return 'short';
            if (this.isDescriptionGarbage()) return 'garbage';
            return 'valid';
        },

        isDescriptionGarbage() {
            const lower = this.description.trim().toLowerCase();
            if (!lower) return false;

            // Que des chiffres / ponctuation
            if (/^[\d\s.,;:!?()\-]+$/.test(lower)) return true;

            // Caractère répété 4+ fois de suite (aaaa, zzzz)
            if (/(.)\1{3,}/.test(lower)) return true;

            const garbageWords = new Set([
                'test', 'lorem', 'ipsum', 'asdf', 'qwerty', 'azerty',
                'xxx', 'abc', 'aaa', 'bbb', 'zzz', '123', '456', '789',
                'toto', 'tata', 'titi', 'blabla', 'foo', 'bar', 'baz',
                'truc', 'machin', 'chose',
            ]);

            const words = lower.split(/\s+/).filter(w => w.length >= 3 && isNaN(w));
            if (words.length === 0) return false;

            // Tous les mots sont des garbage words
            if (words.every(w => garbageWords.has(w))) return true;

            return false;
        },

        get canSubmit() {
            if (this.descriptionStatus !== 'valid') return false;
            if (this.isSpecificClient) return this.clients.some(c => c.id.trim() !== '');
            return true;
        },

        // ─── Pièces jointes ─────────────────

        addAttachments(files) {
            const maxSize = @js(\App\Models\TicketAttachment::maxSizeKb()) * 1024; // supportia.attachments.max_size_kb

            Array.from(files).forEach(file => {
                if (this.attachments.length >= 5) return;
                if (file.size > maxSize) {
                    this.error = `"${file.name}" dépasse la taille maximale de ${@js(\App\Models\TicketAttachment::maxSizeLabel())}.`;
                    return;
                }

                const item = { file, name: file.name, size: file.size, preview: null };

                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = e => { item.preview = e.target.result; };
                    reader.readAsDataURL(file);
                }

                this.attachments.push(item);
            });
        },

        removeAttachment(index) {
            this.attachments.splice(index, 1);
        },

        formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' o';
            if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' Ko';
            return (bytes / (1024 * 1024)).toFixed(1) + ' Mo';
        },

        // ─── Actions ────────────────────────

        async submit() {
            if (!this.canSubmit) return;
            this.error = null;
            this.descriptionError = null;
            this.state = 'loading';
            const start = Date.now();

            try {
                const formData = new FormData();

                // Description + contexte libre si pas de client spécifique
                let description = this.description;
                if (!this.isSpecificClient && this.context.trim()) {
                    description += '\n\nContexte : ' + this.context.trim();
                }
                formData.append('description', description);

                // Clients
                formData.append('is_specific_client', this.isSpecificClient ? '1' : '0');
                if (this.isSpecificClient) {
                    const validClients = this.clients.filter(c => c.id.trim() !== '');
                    formData.append('clients', JSON.stringify(validClients));
                }

                this.attachments.forEach(att => formData.append('attachments[]', att.file));

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
                    if (data.type === 'description_insuffisante') {
                        this.descriptionError = data.error;
                        this.state = 'form';
                        return;
                    }
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


        // Annuler l'écran de validation : le brouillon est supprimé, la saisie est conservée
        async cancelDraft() {
            const ticketId = this.result?.ticket_id;
            this.state = 'form';
            if (!ticketId) return;
            try {
                await fetch(`/support/tickets/${ticketId}/draft`, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
                });
            } catch (e) { /* purgé automatiquement sous 24 h sinon */ }
            this.result = null;
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
                    estimate_hours: data.estimate_hours ?? null,
                    estimate_count: data.estimate_count ?? 0,
                    ticket_id:      data.ticket_id || ticketId,
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
            this.isSpecificClient = true;
            this.clients = [{ uid: Date.now(), id: '', name: '' }];
            this.duplicates = [];
            this.context = '';
            this.attachments = [];
            this.error = null;
            this.descriptionError = null;
            this.result = null;
            this.editResult = null;
            this.glpiTicketId = null;
            this.glpiUrl = null;
            this.createdData = {};
        },

        // ─── Helpers ────────────────────────


        estimateDisplay(hours) {
            if (!hours) return '';
            if (hours < 1) return 'moins d\'une heure';
            if (hours < 24) { const h = Math.round(hours); return '~' + h + ' heure' + (h > 1 ? 's' : ''); }
            const d = Math.round(hours / 24);
            return '~' + d + ' jour' + (d > 1 ? 's' : '');
        },

        getCategoryLabel(slug) {
            const cat = this.categories.find(c => c.slug === slug);
            return cat ? (cat.label_simple || cat.label) : slug;
        },

        priorityLabel(p) {
            return { 1: 'Très basse', 2: 'Basse', 3: 'Normale', 4: 'Haute', 5: 'Critique' }[p] || 'Normale';
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
