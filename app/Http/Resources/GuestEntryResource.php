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
            'entry_type' => $this->entry_type,
            'booking_id' => $this->booking_id,
            'booking_type' => $this->booking?->booking_type ?? null,  // ✅ ADD booking_type from related booking
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

            'payment_status' => $this->billing?->payment_status ?? 'unpaid',
            'amount_paid' => (float) ($this->billing?->amount_paid ?? 0),
            'balance' => (float) ($this->billing?->balance ?? $this->total_amount),
            'payment_method' => $this->billing?->payments?->first()?->payment_method ?? null,

            // ✅ Payment info now comes from billing relationship only
            'is_checked_out' => (bool) $this->is_checked_out,
            'checkout_datetime' => $this->checkout_datetime?->toIso8601String(),  // ✅ ADD THIS
            
            // ✅ Overstay Detection
            'overstay_status' => $this->getOverstayStatus(),
            'scheduled_checkout' => $this->getScheduledCheckout(),
            'is_overstaying' => $this->isOverstaying(),
            'overstay_minutes' => $this->getOverstayMinutes(),
            
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'created_by_user' => $this->whenLoaded('createdBy', function() {
                return [
                    'id' => $this->createdBy->id,
                    'full_name' => $this->createdBy->full_name,
                    'username' => $this->createdBy->username,
                ];
            }),
            'guest_details' => $this->whenLoaded('guestDetails', function() use ($request) {
                return GuestEntryDetailResource::collection($this->guestDetails)->toArray($request);
            }),
            'third_party_services' => $this->whenLoaded('thirdPartyServices', function() use ($request) {
                return ThirdPartyServiceResource::collection($this->thirdPartyServices)->toArray($request);
            }),
            'facilities' => $this->whenLoaded('facilities', function() use ($request) {
                return GuestEntryFacilityResource::collection($this->facilities)->toArray($request);
            }),
            // ✅ REMOVED: payments relationship - payments are now accessed through billing
            'billing' => $this->whenLoaded('billing', function() use ($request) {
                return $this->billing ? [
                    'id' => $this->billing->id,
                    'billing_number' => $this->billing->billing_number,
                    'total_amount' => (float) $this->billing->total_amount,
                    'amount_paid' => (float) $this->billing->amount_paid,
                    'balance' => (float) $this->billing->balance,
                    'payment_status' => ucfirst($this->billing->payment_status),
                    'billing_status' => $this->billing->billing_status,
                    'billed_at' => $this->billing->billed_at?->toIso8601String(),
                    // ✅ Include payments from billing relationship
                    'payments' => $this->billing->payments ? 
                        PaymentResource::collection($this->billing->payments)->toArray($request) : [],
                ] : null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->diffForHumans(),
        ];
    }

    /**
     * Get overstay status for display
     */
    private function getOverstayStatus(): ?string
    {
        if ($this->is_checked_out) {
            return null;
        }

        if ($this->isOverstaying()) {
            $minutes = $this->getOverstayMinutes();
            if ($minutes > 60) {
                $hours = floor($minutes / 60);
                $mins = $minutes % 60;
                return $mins > 0 ? "{$hours}h {$mins}m overstaying" : "{$hours}h overstaying";
            }
            return "{$minutes}m overstaying";
        }

        return null;
    }

    /**
     * Get scheduled checkout datetime
     */
    private function getScheduledCheckout(): ?string
    {
        if ($this->is_checked_out) {
            return null;
        }

        $gracePeriodMinutes = config('billing.overtime.grace_period_minutes', 15);

        if ($this->entry_type === 'walk_in') {
            // For walk-ins: get latest facility checkout
            $latestCheckout = null;
            if ($this->relationLoaded('facilities')) {
                foreach ($this->facilities as $facility) {
                    if ($facility->duration_hours) {
                        $checkout = $this->check_in_datetime
                            ->copy()
                            ->addHours($facility->duration_hours)
                            ->addMinutes($gracePeriodMinutes);
                        
                        if (!$latestCheckout || $checkout->gt($latestCheckout)) {
                            $latestCheckout = $checkout;
                        }
                    }
                }
            }
            return $latestCheckout?->toIso8601String();
        }

        if ($this->booking) {
            return $this->booking->check_out_datetime
                ->copy()
                ->addMinutes($gracePeriodMinutes)
                ->toIso8601String();
        }

        return null;
    }

    /**
     * Check if guest is currently overstaying
     */
    private function isOverstaying(): bool
    {
        if ($this->is_checked_out) {
            return false;
        }

        $scheduledCheckout = $this->getScheduledCheckout();
        if (!$scheduledCheckout) {
            return false;
        }

        return now()->gt(\Carbon\Carbon::parse($scheduledCheckout));
    }

    /**
     * Get overstay minutes
     */
    private function getOverstayMinutes(): int
    {
        if (!$this->isOverstaying()) {
            return 0;
        }

        $scheduledCheckout = $this->getScheduledCheckout();
        if (!$scheduledCheckout) {
            return 0;
        }

        return now()->diffInMinutes(\Carbon\Carbon::parse($scheduledCheckout));
    }
}
