<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-bookings');
    }

    public function rules(): array
    {
        return [
            'guest_name' => 'sometimes|required|string|max:100',
            'contact_number' => 'sometimes|required|string|max:20',
            'facility_id' => 'sometimes|required|exists:facilities,id',
            'rate_id' => 'sometimes|required|exists:rates,id',
            'discount_id' => 'nullable|exists:discounts,id',
            'check_in' => 'sometimes|required|date',
            'check_out' => 'sometimes|required|date|after:check_in',
            'guest_breakdown' => 'nullable|array',
            'total_guests' => 'sometimes|required|integer|min:1',
            'status' => 'sometimes|required|in:Pending,Confirmed,Completed,Cancelled',
        ];
    }
}