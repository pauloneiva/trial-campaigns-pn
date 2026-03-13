<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactListTest extends TestCase
{
    use RefreshDatabase;

    // GET /api/contact-lists

    public function test_contact_lists_are_returned_as_paginated_list(): void
    {
        $count = 5;
        ContactList::factory()->count($count)->create();

        $response = $this->getJson('/api/contact-lists');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name']],
                'total',
            ])
            ->assertJsonCount($count, 'data');
    }

    // POST /api/contact-lists

    public function test_contact_list_can_be_created(): void
    {
        $listName = 'New Subscribers List';
        $response = $this->postJson('/api/contact-lists', ['name' => $listName]);

        $response->assertCreated()
            ->assertJsonPath('name', $listName);

        $this->assertDatabaseHas('contact_lists', ['name' => $listName]);
    }

    public function test_contact_list_creation_requires_a_name(): void
    {
        $response = $this->postJson('/api/contact-lists', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    // POST /api/contact-lists/{id}/contacts

    public function test_contact_can_be_added_to_a_list(): void
    {
        $list    = ContactList::factory()->create();
        $contact = Contact::factory()->create();

        $response = $this->postJson("/api/contact-lists/{$list->id}/contacts", [
            'contact_id' => $contact->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Contact added to list.');

        $this->assertDatabaseHas('contact_contact_list', [
            'contact_list_id' => $list->id,
            'contact_id'      => $contact->id,
        ]);
    }

    public function test_adding_same_contact_twice_returns_conflict(): void
    {
        $list    = ContactList::factory()->create();
        $contact = Contact::factory()->create();
        $list->contacts()->attach($contact);

        $response = $this->postJson("/api/contact-lists/{$list->id}/contacts", [
            'contact_id' => $contact->id,
        ]);

        $response->assertConflict();
    }

    public function test_adding_nonexistent_contact_returns_validation_error(): void
    {
        $list = ContactList::factory()->create();
        $non_existent_id = 999999999;
        $response = $this->postJson("/api/contact-lists/{$list->id}/contacts", [
            'contact_id' => $non_existent_id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['contact_id']);
    }
}
