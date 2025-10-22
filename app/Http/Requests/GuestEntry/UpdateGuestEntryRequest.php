<?php

namespace App\Http\Requests\GuestEntry;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\FacilityAvailable;
use App\Models\Facility;
use Carbon\Carbon;

class UpdateGuestEntryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'guest_name' => 'required|string|max:255',
            'contact_number' => 'required|string|max:20',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {

            // Get guest entry ID being updated
            $guestEntryId = $this->route('id');

        });
    }

    public function messages()
    {
        return [
            'guest_name.required' => 'Guest name is required.',
            'contact_number.required' => 'Contact number is required.',
        ];
    }
}