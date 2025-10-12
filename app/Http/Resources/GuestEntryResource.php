<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class GuestEntryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'entry_reference' => $this->entry_reference,
            'entry_date' => $this->entry_date,
            'entry_time' => $this->entry_time,
            'guest_name' => $this->guest_name,
            'contact_number' => $this->contact_number,
            'total_guests' => $this->total_guests,
            'subtotal' => number_format($this->subtotal, 2),
            'discount_amount' => number_format($this->discount_amount, 2),
            'total_amount' => number_format($this->total_amount, 2),
            'payment_status' => $this->payment_status,
            'amount_paid' => number_format($this->amount_paid, 2),
            'balance' => number_format($this->balance, 2),
            'is_checked_out' => (bool)$this->is_checked_out,
            'notes' => $this->notes,
            'created_by' => $this->creator?->full_name,
            'details' => GuestEntryDetailResource::collection($this->details),
            'facilities' => GuestEntryFacilityResource::collection($this->facilities),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
