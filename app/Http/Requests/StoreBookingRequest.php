<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('manage-bookings');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'guest_name' => 'required|string|max:100',
            'contact_number' => 'required|string|max:20',
            'facility_id' => 'required|exists:facilities,id',
            'rate_id' => 'required|exists:rates,id',
            'discount_id' => 'nullable|exists:discounts,id',
            'check_in' => 'required|date|after:now',
            'check_out' => 'required|date|after:check_in',
            'guest_breakdown' => 'nullable|array',
            'guest_breakdown.adult' => 'nullable|integer|min:0',
            'guest_breakdown.child' => 'nullable|integer|min:0',
            'guest_breakdown.senior' => 'nullable|integer|min:0',
            'guest_breakdown.pwd' => 'nullable|integer|min:0',
            'guest_breakdown.infant' => 'nullable|integer|min:0',
            'total_guests' => 'required|integer|min:1',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'guest_name.required' => 'Guest name is required',
            'contact_number.required' => 'Contact number is required',
            'facility_id.exists' => 'Selected facility does not exist',
            'rate_id.exists' => 'Selected rate does not exist',
            'check_in.after' => 'Check-in date must be in the future',
            'check_out.after' => 'Check-out date must be after check-in date',
            'total_guests.min' => 'At least one guest is required',
        ];
    }
}