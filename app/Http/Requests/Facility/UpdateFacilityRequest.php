<?php

namespace App\Http\Requests\Facility;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFacilityRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'facility_type_id' => 'sometimes|exists:facility_types,id',
            'name' => 'sometimes|string|max:100',
            'quantity' => 'sometimes|integer|min:1',
            'expected_capacity' => 'sometimes|integer|min:0',
            'max_capacity' => 'sometimes|integer|min:0',
            'description' => 'nullable|string',
            'requires_entrance' => 'boolean',
            'is_maintenance' => 'boolean',
            'is_available_for_booking' => 'boolean',
        ];
    }
}