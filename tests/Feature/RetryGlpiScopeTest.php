<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le retry GLPI ne doit relancer que les tickets dont l'envoi a échoué,
 * jamais ceux en attente de validation par le commercial.
 */
class RetryGlpiScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_glpi_scope_excludes_tickets_awaiting_review(): void
    {
        $org  = Organization::create(['name' => 'Org', 'slug' => 'org', 'is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $base = ['organization_id' => $org->id, 'user_id' => $user->id, 'raw_description' => 'x', 'ai_title' => 'Titre', 'status' => 'pending'];

        $awaitingReview = SupportTicket::create($base + ['glpi_retry_count' => 0]);
        $failedOnce     = SupportTicket::create($base + ['glpi_retry_count' => 1]);
        $exhausted      = SupportTicket::create(array_merge($base, ['glpi_retry_count' => 3, 'status' => 'failed']));

        $ids = SupportTicket::pendingGlpi()->pluck('id')->all();

        $this->assertSame([$failedOnce->id], $ids);
        $this->assertNotContains($awaitingReview->id, $ids);
        $this->assertNotContains($exhausted->id, $ids);
    }
}
