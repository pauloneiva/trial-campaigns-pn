<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    // GET /api/contacts

    public function test_contacts_are_returned_as_paginated_list(): void
    {
        $count = 5;
        Contact::factory()->count($count)->create();

        $response = $this->getJson('/api/contacts');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'email', 'status']],
                'current_page',
                'per_page',
                'total',
            ])
            ->assertJsonCount($count, 'data');
    }

    public function test_per_page_parameter_controls_page_size(): void
    {
        $perPage = 3;
        Contact::factory()->count(10)->create();

        $response = $this->getJson("/api/contacts?per_page={$perPage}");

        $response->assertOk()
            ->assertJsonCount($perPage, 'data')
            ->assertJsonPath('per_page', $perPage);
    }

    // POST /api/contacts

    public function test_contact_can_be_created_with_valid_data(): void
    {
        $email = 'toze@example.com';
        $response = $this->postJson('/api/contacts', [
            'name'  => 'António José',
            'email' => $email,
        ]);

        $response->assertCreated()
            ->assertJsonPath('email', $email)
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('contacts', ['email' => $email]);
    }

    public function test_contact_creation_requires_name_and_email(): void
    {
        $response = $this->postJson('/api/contacts', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_contact_creation_rejects_duplicate_email(): void
    {
        $takenEmail = 'taken@example.com';
        Contact::factory()->create(['email' => $takenEmail]);

        $response = $this->postJson('/api/contacts', [
            'name'  => 'The Taken',
            'email' => $takenEmail,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_contact_creation_rejects_invalid_status(): void
    {
        $email = 'toze@example.com';
        $response = $this->postJson('/api/contacts', [
            'name'   => 'António José',
            'email'  => $email,
            'status' => 'banned',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    // POST /api/contacts/{id}/unsubscribe

    public function test_active_contact_can_be_unsubscribed(): void
    {
        $contact = Contact::factory()->create(['status' => 'active']);

        $response = $this->postJson("/api/contacts/{$contact->id}/unsubscribe");

        $response->assertOk()
            ->assertJsonPath('status', 'unsubscribed');

        $this->assertDatabaseHas('contacts', [
            'id'     => $contact->id,
            'status' => 'unsubscribed',
        ]);
    }

    public function test_unsubscribing_nonexistent_contact_returns_404(): void
    {
        $non_existent_id = 999999999;
        $response = $this->postJson("/api/contacts/{$non_existent_id}/unsubscribe");

        $response->assertNotFound();
    }
}
