<?php

namespace App\Http\Requests\FacilityType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFacilityTypeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $id = $this->route('facility_type') ?? $this->route('id');
        
        return [
            'name' => 'sometimes|string|max:100|unique:facility_types,name,' . $id,
            'description' => 'nullable|string',
        ];
    }
}