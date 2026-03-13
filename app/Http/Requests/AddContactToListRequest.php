<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddContactToListRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
        ];
    }
}
