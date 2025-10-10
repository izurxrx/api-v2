<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-rates');
    }

    public function rules(): array
    {
        return [
            'facility_id' => 'nullable|exists:facilities,id',
            'rate_name' => 'required|string|max:100',
            'rate_category' => 'required|in:Entrance,Facility,Exclusive',
            'rate_type' => 'nullable|in:Day_Based,Time_Based',
            'duration_hours' => 'nullable|integer|min:1',
            'extension_fee' => 'nullable|numeric|min:0',
            'price' => 'required|numeric|min:0',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after:valid_from',
        ];
    }
}