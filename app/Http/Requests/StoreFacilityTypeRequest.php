<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFacilityTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-facilities');
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100|unique:facility_types,name',
            'description' => 'nullable|string',
        ];
    }
}