<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pièces jointes : une seule source (config supportia.attachments) pour la création d'un ticket,
 * les réponses et les formulaires. Fichiers réels : le type MIME est détecté sur le contenu (finfo).
 */
class AttachmentRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SupportTicket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $org        = Organization::create(['name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true]);
        $this->user = User::factory()->create(['organization_id' => $org->id]);

        // Ticket sans numéro GLPI : la réponse reste locale (pas de suivi envoyé à GLPI)
        $this->ticket = SupportTicket::create([
            'organization_id' => $org->id, 'user_id' => $this->user->id,
            'raw_description' => 'x', 'ai_title' => 'Import bloqué', 'status' => 'created',
        ]);
    }

    /** Fichier réel sur disque, avec le nom annoncé par le navigateur. */
    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pj');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function fixture(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pj');
        copy(base_path("tests/Fixtures/attachments/{$name}"), $path);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * Création : description trop courte exprès, pour s'arrêter à la validation (aucun appel IA).
     * Renvoie les erreurs portant sur la pièce jointe.
     */
    private function creationErrors(UploadedFile $file): array
    {
        $errors = $this->actingAs($this->user)->post('/support/tickets', [
            'description' => 'court', 'is_specific_client' => '0', 'attachments' => [$file],
        ], ['Accept' => 'application/json'])->assertStatus(422)->json('errors');

        return $errors['attachments.0'] ?? [];
    }

    /** Réponse sur un ticket : renvoie les erreurs portant sur la pièce jointe. */
    private function replyErrors(UploadedFile $file): array
    {
        $this->actingAs($this->user)->post("/support/tickets/{$this->ticket->id}/comment", [
            'content' => 'Voir le fichier', 'attachment' => $file,
        ]);

        return session('errors')?->get('attachment') ?? [];
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return ['création' => ['creationErrors'], 'réponse' => ['replyErrors']];
    }

    #[DataProvider('paths')]
    public function test_size_limit_comes_from_config(string $path): void
    {
        $twoKb = str_repeat('ligne de log ', 160); // ~2 Ko de texte

        config(['supportia.attachments.max_size_kb' => 1]);
        $this->assertSame(['Fichier trop volumineux (maximum 1 Ko).'], $this->{$path}($this->upload('erreur.log', $twoKb)));

        config(['supportia.attachments.max_size_kb' => 3]);
        $this->assertSame([], $this->{$path}($this->upload('erreur.log', $twoKb)));
    }

    #[DataProvider('paths')]
    public function test_excel_files_are_accepted(string $path): void
    {
        $this->assertSame([], $this->{$path}($this->fixture('annonces.xlsx')));
        $this->assertSame([], $this->{$path}($this->fixture('annonces.xls')));
    }

    #[DataProvider('paths')]
    public function test_misleading_or_unlisted_files_are_rejected(string $path): void
    {
        $refused = 'Type de fichier non autorisé. Formats acceptés : images, PDF, CSV, TXT, LOG, Excel.';

        // Script PHP renommé en .txt : l'extension passe, le contenu non
        $this->assertContains($refused, $this->{$path}($this->upload('notes.txt', "<?php system(\$_GET['c']); ?>")));
        // Extension hors liste, même avec un contenu texte
        $this->assertContains($refused, $this->{$path}($this->upload('notes.docx', 'Simple texte')));
        // Texte valide
        $this->assertSame([], $this->{$path}($this->upload('notes.txt', 'Le client ne voit plus ses annonces')));
    }

    public function test_forms_use_the_configured_limit_and_formats(): void
    {
        config(['supportia.attachments.max_size_kb' => 5120]);
        $accept = '.jpg,.jpeg,.png,.gif,.webp,.pdf,.csv,.txt,.log,.xls,.xlsx';
        $this->assertSame($accept, TicketAttachment::acceptAttribute());

        $create = $this->actingAs($this->user)->get('/support')->assertOk()->getContent();
        $this->assertStringContainsString("accept=\"{$accept}\"", $create);
        $this->assertStringContainsString('5 Mo chacun', $create);
        $this->assertStringContainsString('const maxSize = 5120 * 1024', $create);
        $this->assertStringContainsString('Images, PDF, CSV, TXT, LOG, Excel', $create);
        $this->assertStringNotContainsString('10 Mo', $create);

        $detail = $this->get("/support/tickets/{$this->ticket->id}")->assertOk()->getContent();
        $this->assertStringContainsString("accept=\"{$accept}\"", $detail);
    }
}
