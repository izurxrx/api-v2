<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class BookingResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'booking_reference' => $this->booking_reference,
            'guest_name' => $this->guest_name,
            'contact_number' => $this->contact_number,
            'facility' => [
                'id' => $this->facility->id ?? null,
                'name' => $this->facility->name ?? null,
                'max_capacity' => $this->facility->max_capacity ?? 0,
            ],
            'check_in' => [
                'date' => $this->check_in_date,
                'time' => $this->check_in_time,
                'datetime' => optional($this->check_in_date . ' ' . $this->check_in_time),
            ],
            'check_out' => [
                'date' => $this->check_out_date,
                'time' => $this->check_out_time,
                'datetime' => optional($this->check_out_date . ' ' . $this->check_out_time),
            ],
            'actual_check_in_datetime' => $this->actual_check_in_datetime,
            'actual_check_out_datetime' => $this->actual_check_out_datetime,
            'checked_in_by' => $this->checkedInBy?->full_name,
            'checked_out_by' => $this->checkedOutBy?->full_name,
            'number_of_guests' => $this->number_of_guests,
            'guest_breakdown' => $this->guest_breakdown ? json_decode($this->guest_breakdown, true) : null,
            'booking_status' => $this->booking_status,
            'subtotal' => number_format($this->subtotal, 2),
            'discount_amount' => number_format($this->discount_amount, 2),
            'total_amount' => number_format($this->total_amount, 2),
            'payment_status' => $this->payment_status,
            'amount_paid' => number_format($this->amount_paid, 2),
            'balance' => number_format($this->balance, 2),
            'notes' => $this->notes,
            'created_by' => $this->creator?->full_name,
            'created_at' => Carbon::parse($this->created_at)->toDateTimeString(),
            'updated_at' => Carbon::parse($this->updated_at)->toDateTimeString(),
        ];
    }
}
