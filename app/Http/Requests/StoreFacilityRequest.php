<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-facilities');
    }

    public function rules(): array
    {
        return [
            'facility_type_id' => 'required|exists:facility_types,id',
            'name' => 'required|string|max:100',
            'capacity' => 'required|integer|min:1',
            'status' => 'required|in:Available,Unavailable,Under_Maintenance',
            'description' => 'nullable|string',
        ];
    }
}