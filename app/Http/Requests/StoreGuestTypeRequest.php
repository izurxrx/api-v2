<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGuestTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-facilities');
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:50|unique:guest_types,name',
            'description' => 'nullable|string',
            'default_discount_id' => 'nullable|exists:discounts,id',
        ];
    }
}