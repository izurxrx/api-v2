<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_reference' => $this->entry_reference,
            'entry_date' => $this->entry_date?->format('Y-m-d'),
            'entry_time' => $this->entry_time?->format('H:i:s'),
            'guest_name' => $this->guest_name,
            'contact_number' => $this->contact_number,
            'total_guests' => $this->total_guests,
            'entrance_subtotal' => (float) $this->entrance_subtotal,
            'facility_subtotal' => (float) $this->facility_subtotal,
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'total_amount' => (float) $this->total_amount,
            'payment_status' => $this->payment_status,
            'amount_paid' => (float) $this->amount_paid,
            'balance' => (float) $this->balance,
            'is_checked_out' => (bool) $this->is_checked_out,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'created_by_user' => $this->whenLoaded('createdBy', function() {
                return [
                    'id' => $this->createdBy->id,
                    'full_name' => $this->createdBy->full_name,
                    'username' => $this->createdBy->username,
                ];
            }),
            'details' => $this->whenLoaded('details', function() use ($request) {
                return GuestEntryDetailResource::collection($this->details)->toArray($request);
            }),
            'facilities' => $this->whenLoaded('facilities', function() use ($request) {
                return GuestEntryFacilityResource::collection($this->facilities)->toArray($request);
            }),
            'payments' => $this->whenLoaded('payments', function() use ($request) {
                return PaymentResource::collection($this->payments)->toArray($request);
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}