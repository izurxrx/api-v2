<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;

class CancelBookingRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'cancellation_reason' => 'required|string|max:500',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $bookingId = $this->route('id');
            $booking = \App\Models\Booking::find($bookingId);
            
            if (!$booking) {
                return;
            }

            // Cannot cancel if already checked in, checked out, or cancelled
            if (in_array($booking->booking_status, ['Checked_In', 'Checked_Out', 'Cancelled'])) {
                $validator->errors()->add(
                    'booking_status',
                    'Cannot cancel booking with status: ' . $booking->booking_status
                );
                return;
            }

            // Check if past cancellation deadline (72 hours before check-in)
            $now = Carbon::now();
            if ($booking->cancellation_deadline && $now->gt($booking->cancellation_deadline)) {
                $validator->errors()->add(
                    'cancellation_deadline',
                    sprintf(
                        'Cannot cancel booking. Cancellation deadline was %s (72 hours before check-in).',
                        $booking->cancellation_deadline->format('M d, Y h:i A')
                    )
                );
            }
        });
    }

    public function messages()
    {
        return [
            'cancellation_reason.required' => 'Please provide a reason for cancellation',
        ];
    }
}