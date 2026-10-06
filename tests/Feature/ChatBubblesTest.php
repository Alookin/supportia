<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatBubblesTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_text_is_trimmed_and_consecutive_messages_are_grouped(): void
    {
        $org  = Organization::create(['name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true]);
        $team = Team::create(['organization_id' => $org->id, 'name' => 'Commercial', 'slug' => 'commercial']);
        $lea  = User::factory()->create(['organization_id' => $org->id, 'team_id' => $team->id, 'name' => 'Léa Martin']);

        $ticket = SupportTicket::create([
            'organization_id' => $org->id, 'user_id' => $lea->id, 'team_id' => $team->id,
            'raw_description' => 'x', 'ai_title' => 'Import bloqué', 'ai_category_slug' => 'import', 'status' => 'created',
        ]);
        $ticket->comments()->create(['user_id' => $lea->id, 'content' => "  \nLe problème est toujours présent\n  "]);
        $ticket->comments()->create(['user_id' => $lea->id, 'content' => 'Deuxième message']);

        $html = $this->actingAs($lea)->get("/support/tickets/{$ticket->id}")->assertOk()->getContent();

        // Texte collé aux balises, sans espaces parasites (bug du décalage de la 1re ligne)
        $this->assertStringContainsString('<p class="whitespace-pre-wrap break-words">Le problème est toujours présent</p>', $html);
        // Bulle ajustée au contenu
        $this->assertStringContainsString('chat-bubble w-fit', $html);
        // 2e message du même auteur regroupé : en-tête masqué, avatar invisible
        $this->assertSame(1, substr_count($html, '>Vous</span>'));
        $this->assertStringContainsString('mt-1', $html);
    }
}
