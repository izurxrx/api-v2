<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGuestMonitoringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('process-walk-ins');
    }

    public function rules(): array
    {
        return [
            'guest_name' => 'required|string|max:100',
            'contact_number' => 'required|string|max:20',
            'facility_id' => 'required|exists:facilities,id',
            'rate_id' => 'required|exists:rates,id',
            'discount_id' => 'nullable|exists:discounts,id',
            'time_in' => 'required|date',
            'time_out' => 'required|date|after:time_in',
            'guest_breakdown' => 'nullable|array',
            'guest_breakdown.adult' => 'nullable|integer|min:0',
            'guest_breakdown.child' => 'nullable|integer|min:0',
            'guest_breakdown.senior' => 'nullable|integer|min:0',
            'guest_breakdown.pwd' => 'nullable|integer|min:0',
            'guest_breakdown.infant' => 'nullable|integer|min:0',
            'total_guests' => 'required|integer|min:1',
        ];
    }
}