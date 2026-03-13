<?php

namespace Tests\Feature;

use App\Jobs\DispatchCampaign;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\ContactList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    // GET /api/campaigns

    public function test_campaigns_are_returned_as_paginated_list_with_stats(): void
    {
        $campaign = Campaign::factory()->create();
        CampaignSend::factory()->create(['campaign_id' => $campaign->id, 'status' => 'sent']);
        CampaignSend::factory()->create(['campaign_id' => $campaign->id, 'status' => 'failed']);

        $response = $this->getJson('/api/campaigns');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'subject', 'status',
                    'stats' => ['pending', 'sent', 'failed', 'total'],
                ]],
            ])
            ->assertJsonPath('data.0.stats.sent', 1)
            ->assertJsonPath('data.0.stats.failed', 1)
            ->assertJsonPath('data.0.stats.total', 2);
    }

    // POST /api/campaigns

    public function test_campaign_can_be_created_with_valid_data(): void
    {
        $list = ContactList::factory()->create();

        $campaingSubject = 'Campaing Subject';
        $response = $this->postJson('/api/campaigns', [
            'subject'         => $campaingSubject,
            'body'            => 'Campaign body content.',
            'contact_list_id' => $list->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('subject', $campaingSubject)
            ->assertJsonPath('status', 'draft');

        $this->assertDatabaseHas('campaigns', ['subject' => $campaingSubject]);
    }

    public function test_campaign_creation_requires_subject_body_and_list(): void
    {
        $response = $this->postJson('/api/campaigns', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['subject', 'body', 'contact_list_id']);
    }

    public function test_campaign_creation_rejects_nonexistent_contact_list(): void
    {
        $non_existent_id = 999999999;
        $campaingSubject = 'Campaing Subject';
        $response = $this->postJson('/api/campaigns', [
            'subject'         => $campaingSubject,
            'body'            => 'Campaign body content.',
            'contact_list_id' => $non_existent_id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['contact_list_id']);
    }

    public function test_campaign_creation_rejects_scheduled_at_in_the_past(): void
    {
        $list = ContactList::factory()->create();

        $campaingSubject = 'Campaing Subject';
        $response = $this->postJson('/api/campaigns', [
            'subject'         => $campaingSubject,
            'body'            => 'Campaign body content.',
            'contact_list_id' => $list->id,
            'scheduled_at'    => now()->subDay()->toDateTimeString(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_at']);
    }

    // GET /api/campaigns/{id}

    public function test_campaign_is_shown_with_stats(): void
    {
        $campaign = Campaign::factory()->create();
        CampaignSend::factory()->count(3)->create(['campaign_id' => $campaign->id, 'status' => 'pending']);
        CampaignSend::factory()->count(2)->create(['campaign_id' => $campaign->id, 'status' => 'sent']);

        $response = $this->getJson("/api/campaigns/{$campaign->id}");

        $response->assertOk()
            ->assertJsonPath('id', $campaign->id)
            ->assertJsonPath('stats.pending', 3)
            ->assertJsonPath('stats.sent', 2)
            ->assertJsonPath('stats.total', 5);
    }

    public function test_showing_nonexistent_campaign_returns_404(): void
    {
        $non_existent_id = 999999999;
        $response = $this->getJson("/api/campaigns/{$non_existent_id}");

        $response->assertNotFound();
    }

    // POST /api/campaigns/{id}/dispatch

    public function test_draft_campaign_can_be_dispatched(): void
    {
        Queue::fake();

        $contactCount = 3;
        $list     = ContactList::factory()->create();
        $contacts = Contact::factory()->count($contactCount)->create(['status' => 'active']);
        $list->contacts()->attach($contacts);
        $campaign = Campaign::factory()->create([
            'status'          => 'draft',
            'contact_list_id' => $list->id,
        ]);

        $response = $this->postJson("/api/campaigns/{$campaign->id}/dispatch");

        $response->assertOk()
            ->assertJsonPath('message', 'Campaign dispatched successfully.');

        $this->assertDatabaseHas('campaigns', [
            'id'     => $campaign->id,
            'status' => 'sending',
        ]);

        $this->assertDatabaseCount('campaign_sends', $contactCount);
    }

    public function test_dispatching_non_draft_campaign_returns_422(): void
    {
        $campaign = Campaign::factory()->create(['status' => 'sending']);

        $response = $this->postJson("/api/campaigns/{$campaign->id}/dispatch");

        $response->assertUnprocessable();
    }

    public function test_dispatching_campaign_only_queues_active_contacts(): void
    {
        Queue::fake();

        $list            = ContactList::factory()->create();
        $activeContact   = Contact::factory()->create(['status' => 'active']);
        $inactiveContact = Contact::factory()->create(['status' => 'unsubscribed']);
        $list->contacts()->attach([$activeContact->id, $inactiveContact->id]);

        $campaign = Campaign::factory()->create([
            'status'          => 'draft',
            'contact_list_id' => $list->id,
        ]);

        $this->postJson("/api/campaigns/{$campaign->id}/dispatch");

        $this->assertDatabaseCount('campaign_sends', 1);
        $this->assertDatabaseHas('campaign_sends', ['contact_id' => $activeContact->id]);
        $this->assertDatabaseMissing('campaign_sends', ['contact_id' => $inactiveContact->id]);
    }
}
