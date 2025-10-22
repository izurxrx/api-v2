<?php

namespace App\Http\Requests\Rate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRateRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'facility_id' => 'nullable|exists:facilities,id',
            'rate_name' => 'sometimes|string|max:100',
            'rate_category' => 'sometimes|in:Facility,Entrance,Exclusive',
            'rate_type' => 'nullable|in:Day_Based,Time_Based',
            'base_price' => 'sometimes|numeric|min:0',
            'duration' => 'nullable|integer|min:1',
            'extension_fee' => 'nullable|numeric|min:0',
        ];
    }
}