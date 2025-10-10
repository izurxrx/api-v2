<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGuestTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-facilities');
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:50|unique:guest_types,name,' . $this->guest_type->id,
            'description' => 'sometimes|nullable|string',
            'default_discount_id' => 'sometimes|nullable|exists:discounts,id',
        ];
    }
}