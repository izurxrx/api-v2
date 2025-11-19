<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\GuestEntry;
use App\Models\Rate;
use App\Models\Discount;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class OvertimeCalculationService
{
    /**
     * Calculate overtime charges for a walk-in guest entry
     * 
     * @param GuestEntry $guestEntry
     * @param Carbon $exitDatetime
     * @return Collection Collection of overtime charges
     */
    public function calculateGuestEntryOvertime(GuestEntry $guestEntry, Carbon $exitDatetime): Collection
    {
        $overtimeCharges = collect();
        $gracePeriodMinutes = config('billing.overtime.grace_period_minutes', 15);
        
        // Load facilities with rates
        $guestEntry->load(['facilities.rate', 'facilities.facility']);
        
        foreach ($guestEntry->facilities as $facilityEntry) {
            $rate = $facilityEntry->rate;
            
            // Skip if no extension fee
            if (!$rate || $rate->extension_fee <= 0) {
                continue;
            }
            
            // Calculate actual usage time
            $checkInDatetime = $guestEntry->check_in_datetime;
            $allowedEndTime = $checkInDatetime->copy()->addHours($facilityEntry->duration_hours);
            
            // Apply grace period
            $allowedEndTime->addMinutes($gracePeriodMinutes);
            
            // Calculate overtime
            if ($exitDatetime->gt($allowedEndTime)) {
                $overtimeMinutes = $exitDatetime->diffInMinutes($allowedEndTime);
                $overtimeHours = $this->calculateOvertimeHours($overtimeMinutes);
                
                if ($overtimeHours > 0) {
                    $baseOvertimeFee = $overtimeHours * $rate->extension_fee;
                    $discountAmount = 0;
                    $discountId = null;
                    
                    // Apply discount if configured (usually NO for overtime)
                    if (config('billing.overtime.eligible_for_discounts', false)) {
                        // Check if guest entry has applicable discounts
                        if ($guestEntry->discount_id) {
                            $discount = Discount::find($guestEntry->discount_id);
                            if ($discount && $discount->is_active) {
                                $discountAmount = $discount->calculateDiscount($baseOvertimeFee);
                                $discountId = $discount->id;
                            }
                        }
                    }
                    
                    $finalAmount = $baseOvertimeFee - $discountAmount;
                    
                    $overtimeCharges->push([
                        'facility_id' => $facilityEntry->facility_id,
                        'facility_name' => $facilityEntry->facility->name,
                        'rate_id' => $rate->id,
                        'rate_name' => $rate->rate_name,
                        'extension_fee_per_hour' => $rate->extension_fee,
                        'scheduled_end' => $allowedEndTime->format('Y-m-d H:i:s'),
                        'actual_end' => $exitDatetime->format('Y-m-d H:i:s'),
                        'overtime_minutes' => $overtimeMinutes,
                        'overtime_hours' => $overtimeHours,
                        'base_fee' => $baseOvertimeFee,
                        'discount_id' => $discountId,
                        'discount_amount' => $discountAmount,
                        'final_amount' => $finalAmount,
                        'guest_entry_facility_id' => $facilityEntry->id,
                    ]);
                }
            }
        }
        
        return $overtimeCharges;
    }
    
    /**
     * Calculate overtime charges for a booking
     * 
     * @param Booking $booking
     * @param Carbon $actualCheckoutDatetime
     * @return Collection Collection of overtime charges
     */
    public function calculateBookingOvertime(Booking $booking, Carbon $actualCheckoutDatetime): Collection
    {
        $overtimeCharges = collect();
        $gracePeriodMinutes = config('billing.overtime.grace_period_minutes', 15);
        
        // Load facilities with rates
        $booking->load(['facilities.rate', 'facilities.facility']);
        
        foreach ($booking->facilities as $bookingFacility) {
            $rate = $bookingFacility->rate;
            
            // Skip if no extension fee
            if (!$rate || $rate->extension_fee <= 0) {
                continue;
            }
            
            // Determine scheduled checkout time
            // For facilities with specific end_datetime, use that
            // Otherwise use booking checkout_datetime
            $scheduledCheckout = $bookingFacility->end_datetime 
                ?? $booking->check_out_datetime;
            
            // Apply grace period
            $allowedCheckout = $scheduledCheckout->copy()->addMinutes($gracePeriodMinutes);
            
            // Calculate overtime
            if ($actualCheckoutDatetime->gt($allowedCheckout)) {
                $overtimeMinutes = $actualCheckoutDatetime->diffInMinutes($allowedCheckout);
                $overtimeHours = $this->calculateOvertimeHours($overtimeMinutes);
                
                if ($overtimeHours > 0) {
                    $baseOvertimeFee = $overtimeHours * $rate->extension_fee * $bookingFacility->quantity;
                    $discountAmount = 0;
                    $discountId = null;
                    
                    // Apply discount if configured (usually NO for overtime)
                    if (config('billing.overtime.eligible_for_discounts', false)) {
                        if ($booking->discount_id) {
                            $discount = Discount::find($booking->discount_id);
                            if ($discount && $discount->is_active) {
                                $discountAmount = $discount->calculateDiscount($baseOvertimeFee);
                                $discountId = $discount->id;
                            }
                        }
                    }
                    
                    $finalAmount = $baseOvertimeFee - $discountAmount;
                    
                    $overtimeCharges->push([
                        'facility_id' => $bookingFacility->facility_id,
                        'facility_name' => $bookingFacility->facility->name,
                        'rate_id' => $rate->id,
                        'rate_name' => $rate->rate_name,
                        'extension_fee_per_hour' => $rate->extension_fee,
                        'quantity' => $bookingFacility->quantity,
                        'scheduled_checkout' => $scheduledCheckout->format('Y-m-d H:i:s'),
                        'actual_checkout' => $actualCheckoutDatetime->format('Y-m-d H:i:s'),
                        'overtime_minutes' => $overtimeMinutes,
                        'overtime_hours' => $overtimeHours,
                        'base_fee' => $baseOvertimeFee,
                        'discount_id' => $discountId,
                        'discount_amount' => $discountAmount,
                        'final_amount' => $finalAmount,
                        'booking_facility_id' => $bookingFacility->id,
                    ]);
                }
            }
        }
        
        return $overtimeCharges;
    }
    
    /**
     * Calculate overtime hours based on configuration
     * 
     * @param int $minutes
     * @return float
     */
    protected function calculateOvertimeHours(int $minutes): float
    {
        $method = config('billing.overtime.calculation_method', 'hourly');
        
        switch ($method) {
            case 'per_minute':
                return round($minutes / 60, 2);
                
            case 'per_hour_started':
                // Any fraction of an hour counts as full hour
                return ceil($minutes / 60);
                
            case 'hourly':
            default:
                // Round up to nearest hour (standard practice)
                return ceil($minutes / 60);
        }
    }
    
    /**
     * Get preview of overtime charges without saving
     * 
     * @param GuestEntry|Booking $entity
     * @param Carbon $checkoutDatetime
     * @return array
     */
    public function previewOvertime($entity, Carbon $checkoutDatetime): array
    {
        if ($entity instanceof GuestEntry) {
            $overtimeCharges = $this->calculateGuestEntryOvertime($entity, $checkoutDatetime);
        } elseif ($entity instanceof Booking) {
            $overtimeCharges = $this->calculateBookingOvertime($entity, $checkoutDatetime);
        } else {
            throw new \InvalidArgumentException('Entity must be GuestEntry or Booking');
        }
        
        $totalOvertimeAmount = $overtimeCharges->sum('final_amount');
        
        return [
            'has_overtime' => $overtimeCharges->isNotEmpty(),
            'overtime_charges' => $overtimeCharges->toArray(),
            'total_overtime_amount' => $totalOvertimeAmount,
            'grace_period_minutes' => config('billing.overtime.grace_period_minutes', 15),
        ];
    }
}
