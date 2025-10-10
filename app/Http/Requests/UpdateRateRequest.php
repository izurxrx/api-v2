<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-rates');
    }

    public function rules(): array
    {
        return [
            'facility_id' => 'sometimes|nullable|exists:facilities,id',
            'rate_name' => 'sometimes|required|string|max:100',
            'rate_category' => 'sometimes|required|in:Entrance,Facility,Exclusive',
            'rate_type' => 'sometimes|nullable|in:Day_Based,Time_Based',
            'duration_hours' => 'sometimes|nullable|integer|min:1',
            'extension_fee' => 'sometimes|nullable|numeric|min:0',
            'price' => 'sometimes|required|numeric|min:0',
            'valid_from' => 'sometimes|nullable|date',
            'valid_until' => 'sometimes|nullable|date|after:valid_from',
        ];
    }
}