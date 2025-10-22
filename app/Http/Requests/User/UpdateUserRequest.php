<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $userId = $this->route('user') ?? $this->route('id');
        
        return [
            'username' => [
                'required',
                'string',
                'max:50',
                Rule::unique('users', 'username')->ignore($userId)
            ],
            'full_name' => 'required|string|max:100',
            'contact_no' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:6',
            'role' => 'nullable|string|exists:roles,name',
        ];
    }

    public function messages()
    {
        return [
            'username.required' => 'Username is required',
            'username.unique' => 'This username is already taken',
            'full_name.required' => 'Full name is required',
            'password.min' => 'Password must be at least 6 characters',
            'role.exists' => 'Selected role does not exist',
        ];
    }
}