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
            'check_in_datetime' => $this->check_in_datetime?->toIso8601String(),  // ✅ ADD THIS
            'discount_mode' => $this->discount_mode,                               // ✅ ADD THIS
            'discount_id' => $this->discount_id,                                   // ✅ ADD THIS
            'discount' => $this->whenLoaded('discount', function() {               // ✅ ADD THIS
                return $this->discount ? [
                    'id' => $this->discount->id,
                    'name' => $this->discount->name,
                    'category' => $this->discount->category,
                    'type' => $this->discount->type,
                    'value' => (float) $this->discount->value,
                ] : null;
            }),
            'guest_name' => $this->guest_name,
            'contact_number' => $this->contact_number,
            'total_guests' => $this->total_guests,
            'entrance_subtotal' => (float) $this->entrance_subtotal,
            'facility_subtotal' => (float) $this->facility_subtotal,
            'third_party_service_amount' => (float) $this->third_party_service_amount, // ✅ ADD THIS
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'total_amount' => (float) $this->total_amount,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'amount_paid' => (float) $this->amount_paid,
            'balance' => (float) $this->balance,
            'is_checked_out' => (bool) $this->is_checked_out,
            'checkout_datetime' => $this->checkout_datetime?->toIso8601String(),  // ✅ ADD THIS
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
            'third_party_services' => $this->whenLoaded('thirdPartyServices', function() use ($request) {
                return ThirdPartyServiceResource::collection($this->thirdPartyServices)->toArray($request);
            }),
            'facilities' => $this->whenLoaded('facilities', function() use ($request) {
                return GuestEntryFacilityResource::collection($this->facilities)->toArray($request);
            }),
            'payments' => $this->whenLoaded('payments', function() use ($request) {
                return PaymentResource::collection($this->payments)->toArray($request);
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->diffForHumans(),
        ];
    }
}