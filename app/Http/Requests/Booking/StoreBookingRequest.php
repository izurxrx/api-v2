<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\FacilityAvailable;
use App\Models\Facility;
use App\Models\Rate;
use App\Models\Discount;
use Carbon\Carbon;

class StoreBookingRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * Auto-determine discount mode from actual values provided
     */
    public function determineDiscountMode(): string
    {
        $hasDirectDiscounts = !empty($this->guest_discounts);
        $hasSeasonalDiscount = !empty($this->discount_id);
        $hasManualDiscount = !empty($this->manual_discount_amount) && $this->manual_discount_amount > 0;

        // Determine mode based on what's actually provided
        if ($hasDirectDiscounts && $hasManualDiscount) {
            return 'Direct+Manual';
        }
        if ($hasSeasonalDiscount && $hasManualDiscount) {
            return 'Seasonal+Manual';
        }
        if ($hasDirectDiscounts) {
            return 'Direct';
        }
        if ($hasSeasonalDiscount) {
            return 'Seasonal';
        }
        if ($hasManualDiscount) {
            return 'Manual';
        }
        return 'None';
    }

    public function rules()
    {
        return [
            // ✅ BOOKING TYPE (Swimming or Package)
            'booking_type' => 'required|in:Swimming,Package',
            
            // ✅ ENTRANCE RATE (required for Swimming, null for Package)
            'entrance_rate_id' => [
                'nullable',
                'required_if:booking_type,Swimming',
                'exists:rates,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $rate = Rate::find($value);
                        if (!$rate || $rate->rate_category !== 'Entrance') {
                            $fail('Selected rate must be an entrance rate (Day, Night, or Day & Night).');
                        }
                        if ($rate->facility_id !== null) {
                            $fail('Invalid entrance rate. Entrance rates should not be tied to facilities.');
                        }
                    }
                },
            ],
            
            // ✅ GUEST INFORMATION
            'guest_name' => 'required|string|min:2|max:255',
            'contact_number' => 'required|string|regex:/^09[0-9]{9}$/|max:20',
            'number_of_guests' => 'required|integer|min:1|max:1000',
            
            // ✅ GUEST BREAKDOWN (for Direct discounts)
            'guest_breakdown' => 'nullable|array',
            'guest_breakdown.adult' => 'nullable|integer|min:0',
            'guest_breakdown.senior' => 'nullable|integer|min:0',
            'guest_breakdown.child' => 'nullable|integer|min:0',
            'guest_breakdown.infant' => 'nullable|integer|min:0',
            
            // ✅ CHECK-IN/OUT DATES
            'check_in_date' => 'required|date|date_format:Y-m-d',
            'check_out_date' => 'required|date|date_format:Y-m-d|after_or_equal:check_in_date',
            'check_in_time' => 'nullable|date_format:H:i',
            'check_out_time' => 'nullable|date_format:H:i',
            
            // ✅ FACILITIES (cottages for Swimming, venues/rooms for Package)
            'facilities' => 'required|array|min:1|max:50',
            'facilities.*.facility_id' => [
                'required',
                'integer',
                'exists:facilities,id',
                'distinct',
            ],
            'facilities.*.rate_id' => 'required|integer|exists:rates,id',
            'facilities.*.quantity' => 'required|integer|min:1|max:100',
            'facilities.*.rate_amount' => 'required|numeric|min:0|max:9999999.99',
            
            // ✅ DISCOUNT MODE (optional - auto-determined from actual values)
            'discount_mode' => 'nullable|in:None,Direct,Seasonal,Manual',
            'discount_id' => [
                'nullable',
                'exists:discounts,id',
            ],
            'manual_discount_amount' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999.99',
            ],
            
            // ✅ GUEST DISCOUNTS (for Direct mode per-guest discounts)
            'guest_discounts' => 'nullable|array',
            'guest_discounts.*.guest_type' => 'required_with:guest_discounts|string|in:senior,child',
            'guest_discounts.*.count' => 'required_with:guest_discounts|integer|min:1',
            'guest_discounts.*.discount_id' => 'required_with:guest_discounts|exists:discounts,id',
            
            // ✅ THIRD PARTY SERVICES
            'third_party_services' => 'nullable|array|max:50',
            'third_party_services.*.service_name' => 'required_with:third_party_services|string|max:255',
            'third_party_services.*.amount' => 'required_with:third_party_services|numeric|min:0|max:9999999.99',
            
            // ✅ PAYMENT REMOVED - Payments now handled in Billing module
            // 'payment' => 'required|array',
            // 'payment.payment_method' => 'required|in:cash,gcash,bank_transfer,credit_card,debit_card,other',
            // 'payment.amount_paid' => 'required|numeric|min:0|max:9999999.99',
            // 'payment.change_amount' => 'nullable|numeric|min:0|max:9999999.99',
            // 'payment.reference_number' => 'nullable|string|max:255',
            // 'payment.notes' => 'nullable|string|max:1000',
            
            // ✅ ADDITIONAL NOTES
            'special_requests' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    protected function prepareForValidation()
    {
        $data = [];
        
        // Sanitize guest name
        if ($this->has('guest_name')) {
            $data['guest_name'] = trim(strip_tags($this->guest_name));
        }
        
        // Format contact number
        if ($this->has('contact_number')) {
            $data['contact_number'] = preg_replace('/[^0-9]/', '', $this->contact_number);
        }
        
        
        // Calculate guest breakdown total if provided
        if ($this->has('guest_breakdown')) {
            $guestBreakdownTotal = $this->input('guest_breakdown.adult', 0) +
                                 $this->input('guest_breakdown.senior', 0) +
                                 $this->input('guest_breakdown.child', 0) +
                                 $this->input('guest_breakdown.infant', 0);
            
            if ($guestBreakdownTotal > 0 && !$this->has('number_of_guests')) {
                $data['number_of_guests'] = $guestBreakdownTotal;
            }
        }
        
        $this->merge($data);
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            
            // =====================================
            // SWIMMING BOOKING VALIDATION
            // =====================================
            if ($this->booking_type === 'Swimming') {
                
                // ✅ Must have entrance rate
                if (!$this->entrance_rate_id) {
                    $validator->errors()->add('entrance_rate_id', 
                        'Entrance rate selection is required for Swimming bookings. Choose Day, Night, or Day & Night rate.');
                }
                
                // ✅ Must have at least one cottage
                if (!$this->hasAtLeastOneCottage()) {
                    $validator->errors()->add('facilities', 
                        'At least one cottage is required for Swimming bookings.');
                }
                
                // ✅ Cannot be multi-day
                $checkInDate = Carbon::parse($this->check_in_date);
                $checkOutDate = Carbon::parse($this->check_out_date);
                if (!$checkInDate->isSameDay($checkOutDate)) {
                    $validator->errors()->add('check_out_date', 
                        'Swimming bookings must be single-day only. For multi-day stays, please book a Package instead.');
                }
                
                // ✅ Validate entrance rate time slots
                if ($this->entrance_rate_id) {
                    $this->validateEntranceTimeSlot($validator);
                }
            }
            
            // =====================================
            // PACKAGE BOOKING VALIDATION
            // =====================================
            if ($this->booking_type === 'Package') {
                
                if ($this->entrance_rate_id) {
                    $validator->errors()->add('entrance_rate_id', 
                        'Package bookings include swimming access - no entrance fee needed.');
                }
                
                if (!$this->hasVenueOrRoom()) {
                    $validator->errors()->add('facilities', 
                        'Package bookings must include at least one venue or room.');
                }
            }
            
            $this->validateDiscounts($validator);
            
            if ($this->has('guest_breakdown')) {
                $total = ($this->input('guest_breakdown.adult', 0) +
                         $this->input('guest_breakdown.senior', 0) +
                         $this->input('guest_breakdown.child', 0) +
                         $this->input('guest_breakdown.infant', 0));
                
                if ($total !== $this->number_of_guests) {
                    $validator->errors()->add('guest_breakdown', 
                        "Guest breakdown total ({$total}) must equal number of guests ({$this->number_of_guests}).");
                }
            }
            
            // =====================================
            // FACILITY AVAILABILITY & CAPACITY
            // =====================================
            $this->validateFacilityAvailability($validator);
            $this->checkCapacityWarnings($validator);
            
            // =====================================
            // PAYMENT VALIDATION - REMOVED
            // =====================================
            // Payment validation removed - payments now handled in Billing module
            // $this->validatePayment($validator);
        });
    }

    /**
     * Check if booking has at least one cottage
     */
    private function hasAtLeastOneCottage(): bool
    {
        foreach ($this->facilities as $facilityData) {
            $facility = Facility::with('facilityType')->find($facilityData['facility_id']);
            if ($facility && $facility->facilityType && $facility->facilityType->name === 'Cottages') {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if booking has venue or room
     */
    private function hasVenueOrRoom(): bool
    {
        foreach ($this->facilities as $facilityData) {
            $facility = Facility::with('facilityType')->find($facilityData['facility_id']);
            if ($facility && $facility->facilityType) {
                $typeName = $facility->facilityType->name;
                if (in_array($typeName, ['Venue', 'Rooms'])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Validate entrance rate time slots for Swimming bookings
     */
    private function validateEntranceTimeSlot($validator)
    {
        $rate = Rate::find($this->entrance_rate_id);
        if (!$rate) return;
        
        // Set check-in/out times based on entrance rate
        // Day Rate: 8am-5pm, Night Rate: 5pm-10pm, Day & Night: 8am-10pm
        $checkInTime = null;
        $checkOutTime = null;
        
        if (str_contains($rate->rate_name, 'Day Rate')) {
            $checkInTime = '08:00';
            $checkOutTime = '17:00';
        } elseif (str_contains($rate->rate_name, 'Night Rate')) {
            $checkInTime = '17:00';
            $checkOutTime = '22:00';
        } elseif (str_contains($rate->rate_name, 'Day & Night')) {
            $checkInTime = '08:00';
            $checkOutTime = '22:00';
        }
        
        // Auto-set times if not provided
        if (!$this->check_in_time && $checkInTime) {
            $this->merge(['check_in_time' => $checkInTime]);
        }
        if (!$this->check_out_time && $checkOutTime) {
            $this->merge(['check_out_time' => $checkOutTime]);
        }
    }

    /**
     * Validate discount configuration and stacking rules
     */
    private function validateDiscounts($validator)
    {
        // Swimming bookings discount rules
        if ($this->booking_type === 'Swimming') {
            // ✅ Use auto-determined discount mode for validation
            $determinedMode = $this->determineDiscountMode();
            
            // Check Direct + Seasonal stacking (NOT ALLOWED)
            if ($determinedMode === 'Direct' && $this->discount_id) {
                $discount = Discount::find($this->discount_id);
                if ($discount && $discount->category === 'Seasonal_Discount') {
                    $validator->errors()->add('discount_mode', 
                        'Cannot apply both Direct and Seasonal discounts. Choose one or use Manual discount.');
                }
            }
            
            // Validate Direct discount requires guest breakdown
            if ($determinedMode === 'Direct' && !$this->has('guest_discounts')) {
                $validator->errors()->add('guest_discounts', 
                    'Direct discount mode requires specifying which guests receive the discount.');
            }
            
            // Validate guest discounts match Direct category
            if ($this->has('guest_discounts')) {
                foreach ($this->guest_discounts as $index => $guestDiscount) {
                    $discount = Discount::find($guestDiscount['discount_id']);
                    if ($discount && $discount->category !== 'Direct_Discount') {
                        $validator->errors()->add("guest_discounts.{$index}.discount_id", 
                            'Only Direct discounts can be applied per guest.');
                    }
                }
            }
            
            // Validate Seasonal discount
            if ($determinedMode === 'Seasonal' && $this->discount_id) {
                $discount = Discount::find($this->discount_id);
                if ($discount && $discount->category !== 'Seasonal_Discount') {
                    $validator->errors()->add('discount_id', 
                        'Selected discount must be a Seasonal discount.');
                }
                
                // Check if seasonal discount is active for booking date
                $bookingDate = Carbon::parse($this->check_in_date);
                if ($discount->valid_from && $bookingDate->lt($discount->valid_from)) {
                    $validator->errors()->add('discount_id', 
                        'Selected seasonal discount is not yet active.');
                }
                if ($discount->valid_until && $bookingDate->gt($discount->valid_until)) {
                    $validator->errors()->add('discount_id', 
                        'Selected seasonal discount has expired.');
                }
            }
        }
        
        // Package bookings can only have Manual discount
        if ($this->booking_type === 'Package') {
            // ✅ Use auto-determined discount mode for validation
            $determinedMode = $this->determineDiscountMode();
            
            // Check if the determined mode is invalid for Package bookings
            if (!in_array($determinedMode, ['None', 'Manual'])) {
                $validator->errors()->add('discount_mode', 
                    'Package bookings can only have Manual discounts. Direct and Seasonal discounts are for Swimming bookings only.');
            }
        }
        
        // Manual discount validation
        // ✅ Check determined mode, not the submitted one
        $determinedMode = $this->determineDiscountMode();
        if (str_contains($determinedMode, 'Manual')) {
            if (!$this->manual_discount_amount || $this->manual_discount_amount <= 0) {
                $validator->errors()->add('manual_discount_amount', 
                    'Manual discount amount must be greater than 0.');
            }
        }
    }

    /**
     * Validate facility availability
     */
    private function validateFacilityAvailability($validator)
    {
        $checkInDate = Carbon::parse($this->check_in_date);
        $checkInTime = $this->check_in_time ?: '14:00';
        $checkOutDate = Carbon::parse($this->check_out_date);
        $checkOutTime = $this->check_out_time ?: '12:00';
        
        $checkIn = Carbon::parse("{$checkInDate->toDateString()} {$checkInTime}");
        $checkOut = Carbon::parse("{$checkOutDate->toDateString()} {$checkOutTime}");
        
        foreach ($this->facilities as $index => $facilityData) {
            $facility = Facility::find($facilityData['facility_id']);
            
            if (!$facility) continue;
            
            // ✅ FIXED: Check if facility is active (not soft-deleted)
            if ($facility->trashed()) {
                $validator->errors()->add("facilities.{$index}.facility_id",
                    "Facility '{$facility->name}' is not available for booking.");
                continue;
            }

            // ✅ FIXED: Check if facility has any units
            if ($facility->quantity <= 0) {
                $validator->errors()->add("facilities.{$index}.facility_id",
                    "Facility '{$facility->name}' has no available units.");
                continue;
            }
            
            // Check availability
            $availabilityRule = new FacilityAvailable(
                $facility->id,
                $checkIn,
                $checkOut,
                $facilityData['quantity']
            );
            
            $availabilityRule->validate(
                "facilities.{$index}.facility_id",
                $facility->id,
                function($message) use ($validator, $index, $facility) {
                    $validator->errors()->add("facilities.{$index}.facility_id", 
                        "{$facility->name}: {$message}");
                }
            );
        }
    }

    /**
     * Check capacity warnings (non-blocking)
     */
    private function checkCapacityWarnings($validator)
    {
        $warnings = [];
        
        foreach ($this->facilities as $facilityData) {
            $facility = Facility::find($facilityData['facility_id']);
            
            if ($facility && $facility->max_capacity > 0) {
                // Check if number of guests exceeds max capacity
                if ($this->number_of_guests > $facility->max_capacity) {
                    $warnings[] = sprintf(
                        'Warning: Guest count (%d) exceeds maximum capacity (%d) for %s. Booking will proceed but facility may be overcrowded.',
                        $this->number_of_guests,
                        $facility->max_capacity,
                        $facility->name
                    );
                }
                
                // Check if near expected capacity
                if ($this->number_of_guests > $facility->expected_capacity) {
                    $warnings[] = sprintf(
                        'Notice: Guest count (%d) exceeds expected capacity (%d) for %s.',
                        $this->number_of_guests,
                        $facility->expected_capacity,
                        $facility->name
                    );
                }
            }
        }
        
        // Store warnings in session for controller to include in response
        if (!empty($warnings)) {
            session()->flash('capacity_warnings', $warnings);
        }
    }

    /**
     * Validate payment meets 50% downpayment requirement
     */
    private function validatePayment($validator)
    {
        if (!$this->has('payment')) return;
        
        // Calculate total amount
        $facilityTotal = 0;
        $entranceTotal = 0;
        
        // Calculate entrance fees for Swimming bookings
        if ($this->booking_type === 'Swimming' && $this->entrance_rate_id) {
            $entranceRate = Rate::find($this->entrance_rate_id);
            if ($entranceRate) {
                $entranceTotal = $entranceRate->base_price * $this->number_of_guests;
                
                // Apply discounts to entrance
                if ($this->discount_mode === 'Direct' && $this->has('guest_discounts')) {
                    // Calculate per-guest discounts
                    foreach ($this->guest_discounts as $guestDiscount) {
                        $discount = Discount::find($guestDiscount['discount_id']);
                        if ($discount) {
                            $guestCount = $guestDiscount['count'];
                            if ($discount->type === 'Percentage') {
                                $discountAmount = ($entranceRate->base_price * $guestCount) * ($discount->value / 100);
                                $entranceTotal -= $discountAmount;
                            } else {
                                $entranceTotal -= ($discount->value * $guestCount);
                            }
                        }
                    }
                } elseif ($this->discount_mode === 'Seasonal' && $this->discount_id) {
                    // Apply seasonal discount to total entrance
                    $discount = Discount::find($this->discount_id);
                    if ($discount) {
                        if ($discount->type === 'Percentage') {
                            $entranceTotal *= (1 - $discount->value / 100);
                        } else {
                            $entranceTotal -= $discount->value;
                        }
                    }
                }
            }
        }
        
        // Calculate facility fees
        foreach ($this->facilities as $facilityData) {
            $facilityTotal += $facilityData['rate_amount'] * $facilityData['quantity'];
        }
        
        // Add third party services
        $servicesTotal = 0;
        if ($this->has('third_party_services')) {
            foreach ($this->third_party_services as $service) {
                $servicesTotal += $service['amount'];
            }
        }
        
        // Calculate final total
        $subtotal = $entranceTotal + $facilityTotal + $servicesTotal;
        
        // Apply manual discount if present
        if ($this->discount_mode === 'Manual' && $this->manual_discount_amount) {
            $subtotal -= $this->manual_discount_amount;
        }
        
        $totalAmount = max(0, $subtotal);
        $requiredDownpayment = $totalAmount * 0.50; // 50% downpayment
        
        $amountPaid = $this->input('payment.amount_paid', 0);
        
        // ✅ UPDATED: Allow zero payment (Pending booking) or require 50% downpayment (Confirmed booking)
        // Skip validation if payment is zero (booking will be Pending)
        if ($amountPaid > 0 && $amountPaid < $requiredDownpayment) {
            $validator->errors()->add('payment.amount_paid',
                sprintf(
                    'Payment must be either ₱0 (Pending booking) or at least ₱%.2f (50%% downpayment for Confirmed booking). Amount paid: ₱%.2f',
                    $requiredDownpayment,
                    $amountPaid
                )
            );
        }
        
        // Warn if overpayment
        if ($amountPaid > $totalAmount * 1.1) { // 10% buffer for tips/rounding
            $validator->errors()->add('payment.amount_paid',
                sprintf(
                    'Payment amount (₱%.2f) significantly exceeds total amount (₱%.2f). Please verify.',
                    $amountPaid,
                    $totalAmount
                )
            );
        }
    }

    public function messages()
    {
        return [
            'booking_type.required' => 'Please select a booking type (Swimming or Package).',
            'booking_type.in' => 'Booking type must be either Swimming or Package.',
            
            'entrance_rate_id.required_if' => 'Please select an entrance rate (Day, Night, or Day & Night) for Swimming bookings.',
            'entrance_rate_id.exists' => 'Selected entrance rate does not exist.',
            
            'guest_name.required' => 'Guest name is required.',
            'guest_name.min' => 'Guest name must be at least 2 characters.',
            
            'contact_number.required' => 'Contact number is required.',
            'contact_number.regex' => 'Contact number must be a valid Philippine mobile number (09XXXXXXXXX).',
            
            'number_of_guests.required' => 'Number of guests is required.',
            'number_of_guests.min' => 'At least 1 guest is required.',
            
            'check_in_date.required' => 'Check-in date is required.',
            'check_out_date.required' => 'Check-out date is required.',
            'check_out_date.after_or_equal' => 'Check-out date must be on or after check-in date.',
            
            'facilities.required' => 'At least one facility must be selected.',
            'facilities.min' => 'At least one facility must be selected.',
            
            'facilities.*.facility_id.required' => 'Facility selection is required.',
            'facilities.*.facility_id.exists' => 'Selected facility does not exist.',
            'facilities.*.facility_id.distinct' => 'Duplicate facility selected.',
            
            'facilities.*.rate_id.required' => 'Rate selection is required for each facility.',
            'facilities.*.quantity.required' => 'Quantity is required for each facility.',
            'facilities.*.quantity.min' => 'Quantity must be at least 1.',
            
            'discount_mode.required' => 'Please select a discount mode.',
            'discount_id.required_if' => 'Please select a discount for the chosen discount mode.',
            'manual_discount_amount.required_if' => 'Please enter the manual discount amount.',
            
            // Payment messages removed - payments now handled in Billing module
        ];
    }
}