<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_reference' => $this->booking_reference,
            'guest_name' => $this->guest_name,
            'contact_number' => $this->contact_number,
            'facility_id' => $this->facility_id,
            'facility' => $this->whenLoaded('facility', function() {
                return [
                    'id' => $this->facility->id,
                    'name' => $this->facility->name,
                    'facility_type' => [
                        'id' => $this->facility->facilityType->id,
                        'name' => $this->facility->facilityType->name,
                    ],
                ];
            }),
            'check_in_date' => $this->check_in_date?->format('Y-m-d'),
            'check_out_date' => $this->check_out_date?->format('Y-m-d'),
            'check_in_time' => $this->check_in_time,
            'check_out_time' => $this->check_out_time,
            'actual_check_in_datetime' => $this->actual_check_in_datetime?->toIso8601String(),
            'checked_in_by' => $this->checked_in_by,
            'checked_in_by_user' => $this->whenLoaded('checkedInBy', function() {
                return $this->checkedInBy ? [
                    'id' => $this->checkedInBy->id,
                    'full_name' => $this->checkedInBy->full_name,
                ] : null;
            }),
            'actual_check_out_datetime' => $this->actual_check_out_datetime?->toIso8601String(),
            'checked_out_by' => $this->checked_out_by,
            'checked_out_by_user' => $this->whenLoaded('checkedOutBy', function() {
                return $this->checkedOutBy ? [
                    'id' => $this->checkedOutBy->id,
                    'full_name' => $this->checkedOutBy->full_name,
                ] : null;
            }),
            'number_of_guests' => $this->number_of_guests,
            'guest_breakdown' => $this->guest_breakdown,
            'booking_status' => $this->booking_status,
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'third_party_service_amount' => (float) $this->third_party_service_amount,
            'total_amount' => (float) $this->total_amount,
            'payment_status' => $this->payment_status,
            'amount_paid' => (float) $this->amount_paid,
            'balance' => (float) $this->balance,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'created_by_user' => $this->whenLoaded('createdBy', function() {
                return [
                    'id' => $this->createdBy->id,
                    'full_name' => $this->createdBy->full_name,
                ];
            }),
            'payments' => $this->whenLoaded('payments', function() use ($request) {
                return PaymentResource::collection($this->payments)->toArray($request);
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
