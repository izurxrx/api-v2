<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGuestMonitoringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('process-walk-ins');
    }

    public function rules(): array
    {
        return [
            'guest_name' => 'sometimes|required|string|max:100',
            'contact_number' => 'sometimes|required|string|max:20',
            'facility_id' => 'sometimes|required|exists:facilities,id',
            'rate_id' => 'sometimes|required|exists:rates,id',
            'discount_id' => 'nullable|exists:discounts,id',
            'time_in' => 'sometimes|required|date',
            'time_out' => 'sometimes|required|date|after:time_in',
            'guest_breakdown' => 'nullable|array',
            'total_guests' => 'sometimes|required|integer|min:1',
            'status' => 'sometimes|required|in:Ongoing,Completed',
        ];
    }
}