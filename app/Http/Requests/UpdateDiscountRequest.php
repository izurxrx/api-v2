<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-discounts');
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:100',
            'description' => 'sometimes|nullable|string',
            'category' => 'sometimes|required|in:Seasonal,Direct',
            'percentage' => 'sometimes|required|numeric|min:0|max:100',
            'valid_from' => 'sometimes|nullable|date',
            'valid_until' => 'sometimes|nullable|date|after:valid_from',
        ];
    }
}