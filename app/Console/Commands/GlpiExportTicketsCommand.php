<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\GlpiClientService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Export complet des tickets GLPI (+ suivis, solutions, tâches) en JSON Lines.
 *
 * Stratégie (validée par glpi:probe le 02/10/2026 sur glpi.web-eci.com) :
 *  - /Ticket/{id}/ITILSolution et /TicketTask répondent 405 → on n'utilise PAS les sous-ressources.
 *  - Les listes globales /ITILFollowup, /ITILSolution, /TicketTask fonctionnent par lots de 1000
 *    → ~25 appels au lieu de ~11 000 (1 par ticket).
 *  - GLPI concatène un bloc d'erreur après chaque réponse → GlpiClientService::decodeJson().
 *
 * Étapes :
 *  1. Référentiels : users.json (id → nom), categories.json (id → libellé complet)
 *  2. Sous-éléments bruts : raw/{followups,solutions,tasks}/batch-NNNN.jsonl
 *  3. Tickets : tickets/batch-NNNN.jsonl, un ticket par ligne avec ses suivis/solutions/tâches
 *  4. summary.json + log des totaux
 *
 * Reprise : un lot est écrit dans un .tmp puis renommé. Les lots déjà présents sont sautés,
 * il suffit de relancer la même commande après une coupure.
 *
 * Sortie : storage/app/private/glpi-export/ (non versionné — contient des données clients).
 */
class GlpiExportTicketsCommand extends Command
{
    protected $signature = 'glpi:export-tickets
                            {--org=via-mobilis : Slug de l\'organisation}
                            {--batch-size=1000 : Taille des lots (1000 max, limite GLPI)}
                            {--max-batches=0 : Pour tester : nombre max de lots par étape (0 = tout)}
                            {--dir=glpi-export : Dossier de sortie dans storage/app/private}
                            {--fresh : Supprime l\'export existant et repart de zéro}';

    protected $description = 'Exporte tous les tickets GLPI avec suivis, solutions et tâches en JSON Lines (reprise automatique)';

    /** itemtype GLPI => sous-dossier / clé dans le ticket exporté */
    private const SUBITEMS = [
        'ITILFollowup' => 'followups',
        'ITILSolution' => 'solutions',
        'TicketTask'   => 'tasks',
    ];

    private const MAX_ATTEMPTS = 3;
    private const BACKOFF_SECONDS = [5, 15, 30];
    private const TIMEOUT_SECONDS = 120;

    private GlpiClientService $glpi;
    private Organization $org;
    private string $dir;
    private string $base;
    private int $batchSize;
    private int $maxBatches;

    /** @var list<string> Éléments ignorés (illisibles côté GLPI malgré la scission) */
    private array $skipped = [];

    public function handle(GlpiClientService $glpi): int
    {
        $org = Organization::where('slug', $this->option('org'))->first();

        if (! $org?->hasGlpiConfig()) {
            $this->error("Organisation introuvable ou GLPI non configuré : {$this->option('org')}");

            return self::FAILURE;
        }

        $this->glpi       = $glpi;
        $this->org        = $org;
        $this->batchSize  = max(1, min(1000, (int) $this->option('batch-size')));
        $this->maxBatches = max(0, (int) $this->option('max-batches'));
        $this->base       = trim((string) $this->option('dir'), '/\\');

        $disk = Storage::disk('local');

        if ($this->option('fresh')) {
            $disk->deleteDirectory($this->base);
        }

        foreach (['raw/followups', 'raw/solutions', 'raw/tasks', 'tickets'] as $sub) {
            $disk->makeDirectory("{$this->base}/{$sub}");
        }

        $this->dir = $disk->path($this->base);
        $started   = microtime(true);

        $this->info("Export GLPI → {$this->dir}");

        try {
            $users = $this->exportReference('User', 'users.json', function (array $u): ?string {
                $full = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));

                return $full !== '' ? $full : ($u['name'] ?? null);
            });

            $categories = $this->exportReference('ITILCategory', 'categories.json', fn (array $c): ?string => $c['completename'] ?? $c['name'] ?? null);

            foreach (self::SUBITEMS as $itemtype => $folder) {
                $this->exportRaw($itemtype, $folder);
            }

            $this->exportTickets($users, $categories);
        } catch (\Throwable $e) {
            $this->newLine(2);
            $this->error('Export interrompu : ' . $e->getMessage());
            $this->warn('Relance la même commande : les lots déjà écrits sont conservés, la reprise est automatique.');
            Log::error('[GLPI export] interrompu', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $summary = $this->summarize();
        $summary['skipped_count']    = count($this->skipped);
        $summary['skipped_items']    = $this->skipped;
        $summary['duration_seconds'] = (int) (microtime(true) - $started);
        $summary['exported_at']      = now()->toIso8601String();
        file_put_contents("{$this->dir}/summary.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        Log::info('[GLPI export] terminé', $summary);

        $this->newLine();
        $this->table(['indicateur', 'valeur'], collect($summary)->map(fn ($v, $k) => [$k, is_scalar($v) ? $v : json_encode($v)])->values()->all());

        return self::SUCCESS;
    }

    // ─── Étape 1 : référentiels (petits, gardés en mémoire) ────────────────

    /**
     * @return array<int, string|null>
     */
    private function exportReference(string $itemtype, string $file, callable $label): array
    {
        $path = "{$this->dir}/{$file}";

        if (is_file($path)) {
            $this->line("✓ {$itemtype} : déjà exporté ({$file})");

            return json_decode(file_get_contents($path), true) ?? [];
        }

        $map = [];
        [, $total] = $this->fetch($itemtype, 0, 1);
        $batches = (int) ceil($total / $this->batchSize);

        for ($b = 0; $b < $batches; $b++) {
            [$items] = $this->fetch($itemtype, $b * $this->batchSize, $this->batchSize, $total);
            foreach ($items as $item) {
                $map[(int) $item['id']] = $label($item);
            }
        }

        $this->writeAtomic($path, json_encode($map, JSON_UNESCAPED_UNICODE));
        $this->line("✓ {$itemtype} : " . count($map) . " éléments");

        return $map;
    }

    // ─── Étape 2 : sous-éléments bruts ────────────────────────────────────

    private function exportRaw(string $itemtype, string $folder): void
    {
        [, $total] = $this->fetch($itemtype, 0, 1);
        $batches = $this->batchCount($total);

        $this->newLine();
        $this->info("{$itemtype} : {$total} éléments, {$batches} lot(s)");
        $bar = $this->output->createProgressBar($batches);
        $bar->start();

        for ($b = 0; $b < $batches; $b++) {
            $path = sprintf('%s/raw/%s/batch-%04d.jsonl', $this->dir, $folder, $b);

            if (! is_file($path)) {
                [$items] = $this->fetch($itemtype, $b * $this->batchSize, $this->batchSize, $total);
                $lines = array_map(function (array $item) {
                    unset($item['links']);

                    return json_encode($item, JSON_UNESCAPED_UNICODE);
                }, $items);
                $this->writeAtomic($path, $lines ? implode("\n", $lines) . "\n" : '');
            }

            $bar->advance();
        }

        $bar->finish();
    }

    // ─── Étape 3 : tickets + jointure en flux ─────────────────────────────

    private function exportTickets(array $users, array $categories): void
    {
        [, $total] = $this->fetch('Ticket', 0, 1);
        $batches = $this->batchCount($total);

        $this->newLine();
        $this->info("Ticket : {$total} tickets, {$batches} lot(s)");
        $bar = $this->output->createProgressBar($batches);
        $bar->start();

        for ($b = 0; $b < $batches; $b++) {
            $path = sprintf('%s/tickets/batch-%04d.jsonl', $this->dir, $b);

            if (! is_file($path)) {
                [$tickets] = $this->fetch('Ticket', $b * $this->batchSize, $this->batchSize, $total);

                $ids     = array_fill_keys(array_map(fn ($t) => (int) $t['id'], $tickets), true);
                $related = $this->collectRelated($ids, $users);

                $lines = [];
                foreach ($tickets as $ticket) {
                    $id = (int) $ticket['id'];
                    unset($ticket['links']);

                    $ticket['category_name']       = $categories[(int) ($ticket['itilcategories_id'] ?? 0)] ?? null;
                    $ticket['lastupdater_name']    = $users[(int) ($ticket['users_id_lastupdater'] ?? 0)] ?? null;

                    foreach (self::SUBITEMS as $folder) {
                        $items = $related[$folder][$id] ?? [];
                        usort($items, fn ($a, $c) => strcmp((string) ($a['date'] ?? $a['date_creation'] ?? ''), (string) ($c['date'] ?? $c['date_creation'] ?? '')));
                        $ticket[$folder] = $items;
                    }

                    $lines[] = json_encode($ticket, JSON_UNESCAPED_UNICODE);
                }

                $this->writeAtomic($path, $lines ? implode("\n", $lines) . "\n" : '');
                unset($tickets, $related, $lines);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Parcourt les fichiers bruts ligne par ligne et ne garde que les éléments
     * rattachés aux tickets du lot courant (mémoire bornée au lot).
     *
     * @param  array<int, true>  $ids
     * @return array<string, array<int, list<array>>>
     */
    private function collectRelated(array $ids, array $users): array
    {
        $related = [];

        foreach (self::SUBITEMS as $folder) {
            foreach (glob("{$this->dir}/raw/{$folder}/batch-*.jsonl") ?: [] as $file) {
                $handle = fopen($file, 'rb');

                while (($line = fgets($handle)) !== false) {
                    $item = json_decode($line, true);
                    if (! is_array($item)) {
                        continue;
                    }

                    $ticketId = $this->ticketIdOf($folder, $item);
                    if ($ticketId === null || ! isset($ids[$ticketId])) {
                        continue;
                    }

                    $item['author_name'] = $users[(int) ($item['users_id'] ?? 0)] ?? null;
                    $related[$folder][$ticketId][] = $item;
                }

                fclose($handle);
            }
        }

        return $related;
    }

    private function ticketIdOf(string $folder, array $item): ?int
    {
        if ($folder === 'tasks') {
            return isset($item['tickets_id']) ? (int) $item['tickets_id'] : null;
        }

        // Suivis et solutions peuvent aussi concerner des Problèmes / Changements
        if (($item['itemtype'] ?? null) !== 'Ticket' || ! isset($item['items_id'])) {
            return null;
        }

        return (int) $item['items_id'];
    }

    // ─── Étape 4 : synthèse (relit les fichiers, fonctionne aussi après reprise) ──

    private function summarize(): array
    {
        $s = [
            'tickets'                  => 0,
            'tickets_deleted'          => 0,
            'tickets_solved_or_closed' => 0,
            'tickets_without_category' => 0,
            'tickets_with_followups'   => 0,
            'followups'                => 0,
            'followups_private'        => 0,
            'solutions'                => 0,
            'tasks'                    => 0,
            'raw_followups_total'      => 0,
        ];

        foreach (glob("{$this->dir}/tickets/batch-*.jsonl") ?: [] as $file) {
            $handle = fopen($file, 'rb');

            while (($line = fgets($handle)) !== false) {
                $t = json_decode($line, true);
                if (! is_array($t)) {
                    continue;
                }

                $s['tickets']++;
                $s['tickets_deleted']          += (int) ! empty($t['is_deleted']);
                $s['tickets_solved_or_closed'] += (int) in_array((int) ($t['status'] ?? 0), [5, 6], true);
                $s['tickets_without_category'] += (int) empty($t['itilcategories_id']);
                $s['tickets_with_followups']   += (int) ! empty($t['followups']);
                $s['followups']                += count($t['followups'] ?? []);
                $s['followups_private']        += count(array_filter($t['followups'] ?? [], fn ($f) => ! empty($f['is_private'])));
                $s['solutions']                += count($t['solutions'] ?? []);
                $s['tasks']                    += count($t['tasks'] ?? []);
            }

            fclose($handle);
        }

        foreach (glob("{$this->dir}/raw/followups/batch-*.jsonl") ?: [] as $file) {
            $handle = fopen($file, 'rb');
            while (fgets($handle) !== false) {
                $s['raw_followups_total']++;
            }
            fclose($handle);
        }

        // Suivis non rattachés : tickets à la corbeille, Problèmes/Changements, ou --max-batches
        $s['followups_not_attached'] = $s['raw_followups_total'] - $s['followups'];

        return $s;
    }

    // ─── HTTP ─────────────────────────────────────────────────────────────

    /**
     * Récupère un lot avec jusqu'à 3 tentatives espacées.
     *
     * @return array{0: list<array>, 1: int} [éléments, total annoncé par Content-Range]
     */
    private function fetch(string $itemtype, int $offset, int $size, ?int $total = null): array
    {
        $end = $offset + $size - 1;
        if ($total !== null) {
            $end = min($end, $total - 1);
        }
        $effectiveSize = $end - $offset + 1;
        if ($effectiveSize < 1) {
            return [[], $total ?? 0];
        }
        $range = "{$offset}-{$end}";
        $error = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->glpi->get($this->org, "/{$itemtype}", ['range' => $range], self::TIMEOUT_SECONDS);
                $data     = $this->glpi->decodeJson($response->body());

                // Liste d'objets attendue. Une erreur GLPI est une liste de chaînes : ["ERROR_...", "message"]
                $isItemList = is_array($data) && array_is_list($data) && ($data === [] || is_array($data[0]));

                if ($isItemList && $response->status() < 500) {
                    return [$data, $this->parseTotal($response->header('Content-Range'), $total ?? count($data))];
                }

                // Type vide (ex. 0 tâche) : GLPI répond ERROR_RANGE_EXCEED_TOTAL au lieu d'une liste vide
                if (is_array($data) && ($data[0] ?? null) === 'ERROR_RANGE_EXCEED_TOTAL') {
                    return [[], $total ?? 0];
                }

                $error = 'HTTP ' . $response->status() . ' : ' . mb_substr($response->body(), 0, 200);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                $wait = self::BACKOFF_SECONDS[$attempt - 1];
                Log::warning("[GLPI export] {$itemtype} {$range} tentative {$attempt} échouée, nouvel essai dans {$wait}s", ['error' => $error]);
                sleep($wait);
            }
        }

        // Échec persistant : GLPI renvoie par intermittence un HTTP 500 sur les grandes
        // plages (timeout/charge serveur). On scinde la plage en deux et on réessaie,
        // ce qui finit par isoler un éventuel élément réellement illisible.
        if ($effectiveSize > 1) {
            Log::warning("[GLPI export] {$itemtype} {$range} : échec après " . self::MAX_ATTEMPTS . " tentatives, scission de la plage", ['error' => $error]);
            $half = intdiv($effectiveSize, 2);
            [$left]  = $this->fetch($itemtype, $offset, $half, $total);
            [$right] = $this->fetch($itemtype, $offset + $half, $effectiveSize - $half, $total);

            return [array_merge($left, $right), $total ?? (count($left) + count($right))];
        }

        // Plage réduite à un seul élément toujours en échec : illisible côté GLPI → on le saute
        // pour ne pas bloquer l'export complet (consigné dans le log et summary.json).
        Log::error("[GLPI export] {$itemtype} offset {$offset} illisible (HTTP 500 persistant), élément ignoré", ['error' => $error]);
        $this->skipped[] = "{$itemtype}:offset-{$offset}";

        return [[], $total ?? 0];
    }

    private function parseTotal(?string $contentRange, int $fallback): int
    {
        // Format GLPI : "0-999/10886"
        if ($contentRange && preg_match('#/(\d+)$#', trim($contentRange), $m)) {
            return (int) $m[1];
        }

        return $fallback;
    }

    private function batchCount(int $total): int
    {
        $batches = (int) ceil($total / $this->batchSize);

        return $this->maxBatches > 0 ? min($batches, $this->maxBatches) : $batches;
    }

    private function writeAtomic(string $path, string $content): void
    {
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $content);
        rename($tmp, $path);
    }
}
