<?php

namespace App\Http\Requests\Discount;

use Illuminate\Foundation\Http\FormRequest;

class StoreDiscountRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'category' => 'required|in:Seasonal_Discount,Direct_Discount',
            'type' => 'required|in:Percentage,Fixed_Amount',
            'value' => 'required|numeric|min:0',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->type === 'Percentage' && $this->value > 100) {
                $validator->errors()->add('value', 'Percentage discount cannot exceed 100%');
            }
        });
    }

    public function messages()
    {
        return [
            'name.required' => 'Discount name is required',
            'category.required' => 'Please select a discount category',
            'category.in' => 'Invalid discount category',
            'type.required' => 'Please select discount type',
            'type.in' => 'Invalid discount type',
            'value.required' => 'Discount value is required',
            'value.min' => 'Discount value must be at least 0',
            'valid_until.after_or_equal' => 'End date must be after or equal to start date',
        ];
    }
}
