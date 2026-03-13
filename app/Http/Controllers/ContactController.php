<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactRequest;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('per_page', config('pagination.per_page')), config('pagination.max_per_page'));

        return response()->json(Contact::paginate($perPage));
    }

    public function store(StoreContactRequest $request): JsonResponse
    {
        $contact = Contact::create($request->validated());

        return response()->json($contact, 201);
    }

    public function unsubscribe(Contact $contact): JsonResponse
    {
        $contact->update(['status' => 'unsubscribed']);

        return response()->json($contact);
    }
}
