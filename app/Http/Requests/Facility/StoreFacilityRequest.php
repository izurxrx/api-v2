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
            'max_capacity' => 'required|integer|min:0',
            'description' => 'nullable|string',
            'booking_type' => 'required|in:walk_in,booking',
            'is_maintenance' => 'boolean',
            'is_available_for_booking' => 'boolean',
        ];
    }

    public function messages()
    {
        return [
            'facility_type_id.required' => 'Please select a facility type',
            'facility_type_id.exists' => 'Selected facility type does not exist',
            'name.required' => 'Facility name is required',
            'quantity.min' => 'Quantity must be at least 1',
            'booking_type.required' => 'Please select booking type',
            'booking_type.in' => 'Booking type must be either walk_in or booking',
        ];
    }
}