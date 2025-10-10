<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('process-payments');
    }

    public function rules(): array
    {
        return [
            'booking_id' => 'nullable|exists:bookings,id|required_without:guest_monitoring_id',
            'guest_monitoring_id' => 'nullable|exists:guest_monitoring,id|required_without:booking_id',
            'amount_paid' => 'required|numeric|min:0',
            'payment_method' => 'required|string|max:100',
            'payment_date' => 'nullable|date',
            'remarks' => 'nullable|string',
            'payment_reference' => 'nullable|string|max:50',
            'amount_received' => 'nullable|numeric|min:0', // For cash payments to calculate change
        ];
    }

    public function messages(): array
    {
        return [
            'booking_id.required_without' => 'Either booking or guest monitoring entry is required',
            'guest_monitoring_id.required_without' => 'Either booking or guest monitoring entry is required',
        ];
    }
}