<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFacilityTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-facilities');
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:100|unique:facility_types,name,' . $this->facility_type->id,
            'description' => 'sometimes|nullable|string',
        ];
    }
}