<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-facilities');
    }

    public function rules(): array
    {
        return [
            'facility_type_id' => 'sometimes|required|exists:facility_types,id',
            'name' => 'sometimes|required|string|max:100',
            'capacity' => 'sometimes|required|integer|min:1',
            'status' => 'sometimes|required|in:Available,Unavailable,Under_Maintenance',
            'description' => 'nullable|string',
        ];
    }
}