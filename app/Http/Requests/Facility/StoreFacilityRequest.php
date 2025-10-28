<?php

namespace App\Http\Requests\Facility;

use Illuminate\Foundation\Http\FormRequest;

class StoreFacilityRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'facility_type_id' => 'required|exists:facility_types,id',
            'name' => 'required|string|max:100',
            'quantity' => 'required|integer|min:1',
            'expected_capacity' => 'required|integer|min:0',
            'max_capacity' => 'required|integer|gte:expected_capacity',
            'description' => 'nullable|string',
            'requires_entrance' => 'boolean',
        ];
    }

    public function messages()
    {
        return [
            'facility_type_id.required' => 'Please select a facility type',
            'facility_type_id.exists' => 'Selected facility type does not exist',
            'name.required' => 'Facility name is required',
            'quantity.min' => 'Quantity must be at least 1',
            'requires_entrance.required' => 'Please specify if entrance is required',
        ];
    }
}