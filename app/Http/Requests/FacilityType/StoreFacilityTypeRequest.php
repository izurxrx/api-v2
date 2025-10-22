<?php

namespace App\Http\Requests\FacilityType;

use Illuminate\Foundation\Http\FormRequest;

class StoreFacilityTypeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:100|unique:facility_types,name',
            'description' => 'nullable|string',
        ];
    }

    public function messages()
    {
        return [
            'name.required' => 'Facility type name is required',
            'name.unique' => 'This facility type already exists',
        ];
    }
}
