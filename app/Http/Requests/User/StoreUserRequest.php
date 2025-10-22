<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'username' => 'required|string|max:50|unique:users',
            'full_name' => 'required|string|max:100',
            'contact_no' => 'nullable|string|max:20',
            'password' => 'required|string|min:6',
            'role' => 'required|string|exists:roles,name',
        ];
    }

    public function messages()
    {
        return [
            'username.required' => 'Username is required',
            'username.unique' => 'This username is already taken',
            'full_name.required' => 'Full name is required',
            'password.required' => 'Password is required',
            'password.min' => 'Password must be at least 6 characters',
            'role.required' => 'Role is required',
            'role.exists' => 'Selected role does not exist',
        ];
    }
}