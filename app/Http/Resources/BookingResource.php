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
            'booking_type' => $this->booking_type,
            'entrance_rate_id' => $this->entrance_rate_id,
            'entrance_rate' => $this->whenLoaded('entranceRate', function() {
                return $this->entranceRate ? [
                    'id' => $this->entranceRate->id,
                    'rate_name' => $this->entranceRate->rate_name,
                    'duration' => $this->entranceRate->duration,
                    'base_price' => (float) $this->entranceRate->base_price,
                ] : null;
            }),
            'booking_reference' => $this->booking_reference,
            'guest_name' => $this->guest_name,
            'contact_number' => $this->contact_number,
            
            // Datetime fields (new structure)
            'check_in_datetime' => $this->check_in_datetime?->toIso8601String(),
            'check_out_datetime' => $this->check_out_datetime?->toIso8601String(),
            'duration_hours' => $this->duration_hours,
            
            // Backward compatibility (old structure)
            'facility_id' => $this->facility_id,
            'facility' => $this->whenLoaded('facility', function() {
                return $this->facility ? [
                    'id' => $this->facility->id,
                    'name' => $this->facility->name,
                    'facility_type' => [
                        'id' => $this->facility->facilityType->id,
                        'name' => $this->facility->facilityType->name,
                    ],
                ] : null;
            }),
            'check_in_date' => $this->check_in_date?->format('Y-m-d'),
            'check_out_date' => $this->check_out_date?->format('Y-m-d'),
            'check_in_time' => $this->check_in_time,
            'check_out_time' => $this->check_out_time,
            
            // Multi-facility support (NEW)
            'facilities' => $this->whenLoaded('facilities', function() {
                return $this->facilities->map(function($bookingFacility) {
                    return [
                        'id' => $bookingFacility->id,
                        'facility_id' => $bookingFacility->facility_id,
                        'facility' => $bookingFacility->facility ? [
                            'id' => $bookingFacility->facility->id,
                            'name' => $bookingFacility->facility->name,
                            'quantity' => $bookingFacility->facility->quantity,
                            'facility_type' => $bookingFacility->facility->facilityType ? [
                                'id' => $bookingFacility->facility->facilityType->id,
                                'name' => $bookingFacility->facility->facilityType->name,
                            ] : null,
                        ] : null,
                        'rate_id' => $bookingFacility->rate_id,
                        'rate' => $bookingFacility->rate ? [
                            'id' => $bookingFacility->rate->id,
                            'rate_name' => $bookingFacility->rate->rate_name,
                            'duration' => $bookingFacility->rate->duration,
                            'base_price' => (float) $bookingFacility->rate->base_price,
                        ] : null,
                        'start_datetime' => $bookingFacility->start_datetime?->toIso8601String(),
                        'end_datetime' => $bookingFacility->end_datetime?->toIso8601String(),
                        'duration_hours' => $bookingFacility->duration_hours,
                        'base_amount' => (float) $bookingFacility->base_amount,
                        'quantity' => $bookingFacility->quantity,
                    ];
                });
            }),
            
            // Third-party services (NEW)
            'third_party_services' => $this->whenLoaded('thirdPartyServices', function() {
                return $this->thirdPartyServices->map(function($service) {
                    return [
                        'id' => $service->id,
                        'service_name' => $service->service_name,
                        'amount' => (float) $service->amount,
                    ];
                });
            }),
            
            // Check-in/out tracking
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
            
            // Guest information
            'number_of_guests' => $this->number_of_guests,
            'guest_breakdown' => $this->guest_breakdown,
            
            // Status
            'booking_status' => $this->booking_status,
            'cancellation_deadline' => $this->cancellation_deadline?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            
            // Amounts
            'facility_subtotal' => (float) $this->facility_subtotal,
            'third_party_service_amount' => (float) $this->third_party_service_amount,
            'subtotal' => (float) $this->subtotal,
            'total_amount' => (float) $this->total_amount,
            
            // Payment fields (from billing relationship)
            'payment_status' => $this->billing?->payment_status ?? 'unpaid',
            'amount_paid' => (float) ($this->billing?->amount_paid ?? 0),
            'balance' => (float) ($this->billing?->balance ?? $this->total_amount),
            
            // Calculated fields
            'minimum_deposit' => (float) ($this->total_amount * 0.5),
            'can_be_cancelled' => $this->canBeCancelled(),
            'requires_payment' => $this->requiresPayment(),
            'has_minimum_deposit' => $this->hasMinimumDeposit(),
            
            // Notes
            'special_requests' => $this->special_requests,
            'notes' => $this->notes,
            
            // Created by
            'created_by' => $this->created_by,
            'created_by_user' => $this->whenLoaded('createdBy', function() {
                return [
                    'id' => $this->createdBy->id,
                    'full_name' => $this->createdBy->full_name,
                ];
            }),
            
            // Payments
            'payments' => $this->whenLoaded('payments', function() use ($request) {
                return PaymentResource::collection($this->payments)->toArray($request);
            }),
            
            // Billing
            'billing' => $this->whenLoaded('billing', function() {
                return $this->billing ? [
                    'id' => $this->billing->id,
                    'billing_number' => $this->billing->billing_number,
                    'total_amount' => (float) $this->billing->total_amount,
                    'amount_paid' => (float) $this->billing->amount_paid,
                    'balance' => (float) $this->billing->balance,
                    'downpayment_amount' => (float) $this->billing->downpayment_amount,
                    'downpayment_paid' => (float) $this->billing->downpayment_paid,
                    'is_downpayment_paid' => (bool) $this->billing->is_downpayment_paid,
                    'payment_status' => $this->billing->payment_status,
                    'billing_status' => $this->billing->billing_status,
                    'billed_at' => $this->billing->billed_at?->toIso8601String(),
                ] : null;
            }),
            
            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),

            'discount_mode' => $this->discount_mode,
            'discount_id' => $this->discount_id,
            'discount' => $this->whenLoaded('discount', function() {
                return $this->discount ? [
                    'id' => $this->discount->id,
                    'name' => $this->discount->discount_name,
                    'category' => $this->discount->category,
                    'type' => $this->discount->discount_type,
                    'value' => (float) $this->discount->value,
                    'formatted_value' => $this->discount->type === 'Percentage'
                        ? rtrim(rtrim(number_format($this->discount->value, 2), '0'), '.') . '%'
                        : '₱' . number_format($this->discount->value, 2),
                ] : null;
            }),
            'manual_discount_amount' => (float) $this->manual_discount_amount,
            'discount_amount' => (float) $this->discount_amount,
        ];
    }
}