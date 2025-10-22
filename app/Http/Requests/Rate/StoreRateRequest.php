<?php

namespace App\Http\Requests\Rate;

use Illuminate\Foundation\Http\FormRequest;

class StoreRateRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'facility_id' => 'nullable|exists:facilities,id',
            'rate_name' => 'required|string|max:100',
            'rate_category' => 'required|in:Facility,Entrance,Exclusive',
            'rate_type' => 'nullable|in:Day_Based,Time_Based',
            'base_price' => 'required|numeric|min:0',
            'duration' => 'nullable|integer|min:1',
            'extension_fee' => 'nullable|numeric|min:0',
        ];
    }

    public function messages()
    {
        return [
            'rate_name.required' => 'Rate name is required',
            'rate_category.required' => 'Rate category is required',
            'rate_category.in' => 'Invalid rate category',
            'rate_type.in' => 'Invalid rate type',
            'base_price.required' => 'Base price is required',
            'base_price.min' => 'Base price must be at least 0',
            'duration.min' => 'Duration must be at least 1 hour',
        ];
    }
}