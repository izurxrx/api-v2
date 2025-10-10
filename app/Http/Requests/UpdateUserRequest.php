<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-users');
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100|unique:users,name,' . $this->user->id,
            'password' => 'nullable|string|min:6',
            'role' => 'nullable|string|exists:roles,name',
        ];
    }
}