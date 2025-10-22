<?php

namespace App\Http\Requests\Discount;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDiscountRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'category' => 'sometimes|in:Seasonal_Discount,Direct_Discount',
            'type' => 'sometimes|in:Percentage,Fixed_Amount',
            'value' => 'sometimes|numeric|min:0',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->has('type') && $this->type === 'Percentage' && $this->value > 100) {
                $validator->errors()->add('value', 'Percentage discount cannot exceed 100%');
            }
        });
    }
}