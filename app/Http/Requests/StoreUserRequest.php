<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Spatie\Permission\Models\Role;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-users');
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100|unique:users,name',
            'password' => 'required|string|min:6',
            'role' => 'required|string|exists:roles,name',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Username is required',
            'name.unique' => 'This username is already taken',
            'password.required' => 'Password is required',
            'password.min' => 'Password must be at least 6 characters',
            'role.required' => 'Role is required',
            'role.exists' => 'Selected role does not exist',
        ];
    }
}