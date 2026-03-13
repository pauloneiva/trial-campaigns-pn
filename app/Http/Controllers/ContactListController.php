<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddContactToListRequest;
use App\Http\Requests\StoreContactListRequest;
use App\Models\ContactList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactListController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('per_page', config('pagination.per_page')), config('pagination.max_per_page'));

        return response()->json(ContactList::paginate($perPage));
    }

    public function store(StoreContactListRequest $request): JsonResponse
    {
        $list = ContactList::create($request->validated());

        return response()->json($list, 201);
    }

    public function addContact(AddContactToListRequest $request, ContactList $contactList): JsonResponse
    {
        $result = $contactList->contacts()->syncWithoutDetaching([$request->contact_id]);

        if (empty($result['attached'])) {
            return response()->json(['message' => 'Contact is already in this list.'], 409);
        }

        return response()->json(['message' => 'Contact added to list.'], 201);
    }
}
