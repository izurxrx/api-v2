<?php

namespace App\Http\Requests\GuestEntry;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\FacilityAvailable;
use App\Models\Facility;
use App\Models\Rate;
use App\Models\Discount;
use Carbon\Carbon;

class StoreGuestEntryRequest extends FormRequest
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
        $hasDirectDiscounts = false;
        
        // Check guest_details for discounts
        if ($this->has('guest_details')) {
            foreach ($this->guest_details as $detail) {
                if (!empty($detail['discount_id'])) {
                    $hasDirectDiscounts = true;
                    break;
                }
            }
        }
        
        // Check guest_discounts for discounts
        if (!$hasDirectDiscounts && $this->has('guest_discounts')) {
            foreach ($this->guest_discounts as $discount) {
                if (!empty($discount['discount_id'])) {
                    $hasDirectDiscounts = true;
                    break;
                }
            }
        }

        $hasSeasonalDiscount = !empty($this->seasonal_discount_id);
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
            // ✅ ENTRANCE RATE (required for walk-in swimming)
            'entrance_rate_id' => [
                'required',
                'exists:rates,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $rate = Rate::find($value);
                        if (!$rate || $rate->rate_category !== 'Entrance') {
                            $fail('Selected rate must be an entrance rate (Day, Night, or Day & Night).');
                        }
                    }
                },
            ],
            
            // ✅ GUEST INFORMATION
            'guest_name' => 'required|string|min:2|max:255',
            'contact_number' => 'nullable|string|regex:/^09[0-9]{9}$/|max:20',
            'entry_date' => 'required|date|date_format:Y-m-d|before_or_equal:today',
            'check_in_time' => 'nullable|date_format:H:i',
            'number_of_guests' => 'required|integer|min:1|max:1000',
            
            // ✅ GUEST BREAKDOWN (unified with booking structure)
            'guest_breakdown' => 'sometimes|array',
            'guest_breakdown.adult' => 'required_with:guest_breakdown|integer|min:0',
            'guest_breakdown.senior' => 'required_with:guest_breakdown|integer|min:0',
            'guest_breakdown.child' => 'required_with:guest_breakdown|integer|min:0',
            
            // ✅ GUEST DISCOUNTS (unified with booking structure)
            'guest_discounts' => 'sometimes|array',
            'guest_discounts.*.guest_type' => 'required|string|in:adult,senior,child',
            'guest_discounts.*.count' => 'required|integer|min:1',
            'guest_discounts.*.discount_id' => [
                'nullable',
                'exists:discounts,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $discount = Discount::find($value);
                        if ($discount && $discount->category !== 'Direct_Discount') {
                            $fail('Only Direct discounts can be applied per guest type.');
                        }
                    }
                },
            ],
            
            // ✅ LEGACY GUEST DETAILS (backwards compatibility)
            'guest_details' => 'sometimes|array|min:1',
            'guest_details.*.guest_type_name' => 'required_with:guest_details|string|in:Regular,Senior Citizen,Children below 2 yrs old',
            'guest_details.*.guest_count' => 'required_with:guest_details|integer|min:1',
            'guest_details.*.discount_id' => 'nullable|exists:discounts,id',
            
            // ✅ FACILITIES (cottages for walk-in swimming)
            'facilities' => 'nullable|array',
            'facilities.*.facility_id' => [
                'required_with:facilities',
                'integer',
                'exists:facilities,id',
                'distinct',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $facility = Facility::find($value);
                        // Walk-ins can only book cottages
                        if ($facility && $facility->facilityType) {
                            if ($facility->facilityType->name !== 'Cottages') {
                                $fail('Walk-in guests can only book cottages.');
                            }
                        }
                    }
                },
            ],
            'facilities.*.rate_id' => 'required_with:facilities|exists:rates,id',
            'facilities.*.quantity' => 'required_with:facilities|integer|min:1|max:100',
            
            // ✅ SEASONAL DISCOUNT (optional, auto-applies if active)
            'seasonal_discount_id' => [
                'nullable',
                'exists:discounts,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $discount = Discount::find($value);
                        if ($discount && $discount->category !== 'Seasonal_Discount') {
                            $fail('Selected discount must be a seasonal discount.');
                        }
                    }
                },
            ],
            
            // ✅ MANUAL DISCOUNT (optional staff discount)
            'manual_discount_amount' => 'nullable|numeric|min:0|max:9999999.99',
            
            // ✅ PAYMENT (OPTIONAL - can be recorded later via billing module)
            'payment' => 'sometimes|array',
            'payment.amount_paid' => 'required_with:payment|numeric|min:0',
            'payment.payment_method' => 'required_with:payment|string|in:Cash,Card,GCash,Bank Transfer,Online',
            'payment.change_amount' => 'nullable|numeric|min:0',
            'payment.reference_number' => 'nullable|string|max:255',
            'payment.notes' => 'nullable|string|max:500',
            
            // ✅ NOTES
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
        
        // Set entry date to today if not provided
        if (!$this->has('entry_date')) {
            $data['entry_date'] = Carbon::today()->toDateString();
        }
        
        // Set check-in time to now if not provided
        if (!$this->has('check_in_time')) {
            $data['check_in_time'] = Carbon::now()->format('H:i');
        }
        
        // ✅ UNIFIED STRUCTURE: Convert guest_breakdown to guest_details format
        if ($this->has('guest_breakdown')) {
            $breakdown = $this->input('guest_breakdown');
            $guestDetails = [];
            
            // Convert adult
            $adultCount = $breakdown['adult'] ?? 0;
            if ($adultCount > 0) {
                $guestDetails[] = [
                    'guest_type_name' => 'Regular',
                    'guest_count' => $adultCount,
                    'discount_id' => null,
                ];
            }
            
            // Convert senior
            $seniorCount = $breakdown['senior'] ?? 0;
            if ($seniorCount > 0) {
                $guestDetails[] = [
                    'guest_type_name' => 'Senior Citizen',
                    'guest_count' => $seniorCount,
                    'discount_id' => null,
                ];
            }
            
            // Convert child
            $childCount = $breakdown['child'] ?? 0;
            if ($childCount > 0) {
                $guestDetails[] = [
                    'guest_type_name' => 'Children below 2 yrs old',
                    'guest_count' => $childCount,
                    'discount_id' => null,
                ];
            }
            
            // Apply guest_discounts to matching guest types
            if ($this->has('guest_discounts')) {
                foreach ($this->guest_discounts as $discount) {
                    $guestType = $discount['guest_type'];
                    $targetTypeName = match($guestType) {
                        'adult' => 'Regular',
                        'senior' => 'Senior Citizen',
                        'child' => 'Children below 2 yrs old',
                        default => null,
                    };
                    
                    if ($targetTypeName) {
                        foreach ($guestDetails as $key => $detail) {
                            if ($detail['guest_type_name'] === $targetTypeName) {
                                $guestDetails[$key]['discount_id'] = $discount['discount_id'] ?? null;
                                break;
                            }
                        }
                    }
                }
            }
            
            $data['guest_details'] = $guestDetails;
        }
        
        // Calculate total guests from guest_details
        if ($this->has('guest_details') || isset($data['guest_details'])) {
            $guestDetailsToCount = $data['guest_details'] ?? $this->guest_details;
            $totalGuests = 0;
            foreach ($guestDetailsToCount as $detail) {
                $totalGuests += $detail['guest_count'] ?? 0;
            }
            
            if ($totalGuests > 0 && !$this->has('number_of_guests')) {
                $data['number_of_guests'] = $totalGuests;
            }
        }
        
        // // Auto-detect active seasonal discount if not specified
        // if (!$this->has('seasonal_discount_id')) {
        //     $today = Carbon::today();
        //     $activeSeasonalDiscount = Discount::where('category', 'Seasonal_Discount')
        //         ->where('is_active', true)
        //         ->where(function($q) use ($today) {
        //             $q->whereNull('valid_from')
        //               ->orWhere('valid_from', '<=', $today);
        //         })
        //         ->where(function($q) use ($today) {
        //             $q->whereNull('valid_until')
        //               ->orWhere('valid_until', '>=', $today);
        //         })
        //         ->first();
            
        //     if ($activeSeasonalDiscount) {
        //         $data['seasonal_discount_id'] = $activeSeasonalDiscount->id;
        //     }
        // }
        
        $this->merge($data);
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            
            // =====================================
            // ENTRANCE RATE VALIDATION
            // =====================================
            if ($this->entrance_rate_id) {
                $entranceRate = Rate::find($this->entrance_rate_id);
                if (!$entranceRate) {
                    return;
                }
                
                // Validate time slot matches current time
                $now = Carbon::now();
                $currentHour = $now->hour;
                
                if (str_contains($entranceRate->rate_name, 'Day Rate')) {
                    // Day Rate: 8am-5pm
                    if ($currentHour < 8 || $currentHour >= 17) {
                        $validator->errors()->add('entrance_rate_id',
                            'Day Rate is only available from 8:00 AM to 5:00 PM.');
                    }
                } elseif (str_contains($entranceRate->rate_name, 'Night Rate')) {
                    // Night Rate: 5pm-10pm
                    if ($currentHour < 17 || $currentHour >= 22) {
                        $validator->errors()->add('entrance_rate_id',
                            'Night Rate is only available from 5:00 PM to 10:00 PM.');
                    }
                }
                // Day & Night rate is available all day
            }
            
            // =====================================
            // GUEST DETAILS VALIDATION
            // =====================================
            if ($this->has('guest_details')) {
                $totalFromDetails = 0;
                $hasDirectDiscounts = false;
                
                foreach ($this->guest_details as $index => $detail) {
                    $guestCount = $detail['guest_count'] ?? 0;
                    $totalFromDetails += $guestCount;
                    
                    // Check if discount is valid Direct type
                    if (isset($detail['discount_id']) && $detail['discount_id']) {
                        $discount = Discount::find($detail['discount_id']);
                        if (!$discount) {
                            $validator->errors()->add("guest_details.{$index}.discount_id",
                                'Invalid discount selected.');
                        } elseif ($discount->category !== 'Direct_Discount') {
                            $validator->errors()->add("guest_details.{$index}.discount_id",
                                'Only Direct discounts can be applied per guest type.');
                        } elseif (!$discount->is_active) {
                            $validator->errors()->add("guest_details.{$index}.discount_id",
                                'Selected discount is not active.');
                        }
                        
                        $hasDirectDiscounts = true;
                    }
                }
                
                // Validate total matches
                if ($totalFromDetails !== $this->number_of_guests) {
                    $validator->errors()->add('guest_details',
                        "Guest details total ({$totalFromDetails}) must equal number of guests ({$this->number_of_guests}).");
                }
                
                // Check discount stacking rules
                if ($hasDirectDiscounts && $this->seasonal_discount_id) {
                    // Direct + Seasonal is NOT allowed
                    $validator->errors()->add('seasonal_discount_id',
                        'Cannot apply both Direct (per-guest) and Seasonal discounts. Remove one or use Manual discount instead.');
                }
            }
            
            // =====================================
            // FACILITY AVAILABILITY
            // =====================================
            if ($this->has('facilities')) {
                $entryDate = Carbon::parse($this->entry_date);
                $checkInTime = $this->check_in_time ?: Carbon::now()->format('H:i');
                $checkIn = Carbon::parse("{$entryDate->toDateString()} {$checkInTime}");
                
                // For walk-ins, assume they stay until closing (10pm)
                $checkOut = Carbon::parse("{$entryDate->toDateString()} 22:00");
                
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
            
            // =====================================
            // CAPACITY WARNINGS (non-blocking)
            // =====================================
            $this->checkCapacityWarnings($validator);
            
            // ❌ PAYMENT VALIDATION REMOVED
            // Payment is now handled separately in the Billing module
            // Walk-in entries create billing with unpaid status
            // $this->validatePayment($validator);
        });
    }

    /**
     * Check capacity warnings for facilities
     */
    private function checkCapacityWarnings($validator)
    {
        $warnings = [];
        
        if ($this->has('facilities')) {
            foreach ($this->facilities as $facilityData) {
                $facility = Facility::find($facilityData['facility_id']);
                
                if ($facility && $facility->max_capacity > 0) {
                    if ($this->number_of_guests > $facility->max_capacity) {
                        $warnings[] = sprintf(
                            'Warning: Guest count (%d) exceeds maximum capacity (%d) for %s.',
                            $this->number_of_guests,
                            $facility->max_capacity,
                            $facility->name
                        );
                    }
                }
            }
        }
        
        // Store warnings in session
        if (!empty($warnings)) {
            session()->flash('capacity_warnings', $warnings);
        }
    }

    /**
     * Validate payment equals total amount (walk-ins pay full)
     */
    private function validatePayment($validator)
    {
        if (!$this->has('payment')) return;
        
        // Calculate entrance fee
        $entranceTotal = 0;
        if ($this->entrance_rate_id) {
            $entranceRate = Rate::find($this->entrance_rate_id);
            if ($entranceRate) {
                // Base entrance for all guests
                $baseEntrance = $entranceRate->base_price * $this->number_of_guests;
                $entranceTotal = $baseEntrance;
                
                // Apply Direct discounts (per guest type)
                if ($this->has('guest_details')) {
                    $totalDirectDiscount = 0;
                    
                    foreach ($this->guest_details as $detail) {
                        if (isset($detail['discount_id']) && $detail['discount_id']) {
                            $discount = Discount::find($detail['discount_id']);
                            if ($discount && $discount->category === 'Direct_Discount') {
                                $guestCount = $detail['guest_count'];
                                
                                if ($discount->type === 'Percentage') {
                                    $discountPerGuest = $entranceRate->base_price * ($discount->value / 100);
                                } else {
                                    $discountPerGuest = min($discount->value, $entranceRate->base_price);
                                }
                                
                                $totalDirectDiscount += $discountPerGuest * $guestCount;
                            }
                        }
                    }
                    
                    $entranceTotal -= $totalDirectDiscount;
                }
                
                // Apply Seasonal discount (to total entrance)
                if ($this->seasonal_discount_id) {
                    $seasonalDiscount = Discount::find($this->seasonal_discount_id);
                    if ($seasonalDiscount && $seasonalDiscount->is_active) {
                        if ($seasonalDiscount->type === 'Percentage') {
                            $entranceTotal *= (1 - $seasonalDiscount->value / 100);
                        } else {
                            $entranceTotal -= min($seasonalDiscount->value, $entranceTotal);
                        }
                    }
                }
            }
        }
        
        // Calculate facility fees
        $facilityTotal = 0;
        if ($this->has('facilities')) {
            foreach ($this->facilities as $facilityData) {
                $rate = Rate::find($facilityData['rate_id']);
                if ($rate) {
                    $facilityTotal += $rate->base_price * $facilityData['quantity'];
                }
            }
        }
        
        // Calculate total
        $subtotal = max(0, $entranceTotal + $facilityTotal);
        
        // Apply manual discount if present
        if ($this->manual_discount_amount) {
            $subtotal -= $this->manual_discount_amount;
        }
        
        $totalAmount = max(0, $subtotal);
        $amountPaid = $this->input('payment.amount_paid', 0);
        
        // Walk-ins must pay full amount
        if ($amountPaid < $totalAmount) {
            $validator->errors()->add('payment.amount_paid',
                sprintf(
                    'Walk-in guests must pay the full amount of ₱%.2f. Amount paid: ₱%.2f',
                    $totalAmount,
                    $amountPaid
                )
            );
        }
        
        // Warn if significant overpayment
        if ($amountPaid > $totalAmount * 1.5) {
            $validator->errors()->add('payment.amount_paid',
                sprintf(
                    'Payment amount (₱%.2f) significantly exceeds total (₱%.2f). Please verify.',
                    $amountPaid,
                    $totalAmount
                )
            );
        }
    }

    public function messages()
    {
        return [
            'entrance_rate_id.required' => 'Please select an entrance rate (Day, Night, or Day & Night).',
            'entrance_rate_id.exists' => 'Selected entrance rate does not exist.',
            
            'guest_name.required' => 'Guest name is required.',
            'guest_name.min' => 'Guest name must be at least 2 characters.',
            
            'contact_number.regex' => 'Contact number must be a valid Philippine mobile number (09XXXXXXXXX).',
            
            'entry_date.required' => 'Entry date is required.',
            'entry_date.before_or_equal' => 'Entry date cannot be in the future.',
            
            'number_of_guests.required' => 'Number of guests is required.',
            'number_of_guests.min' => 'At least 1 guest is required.',
            
            'guest_details.required' => 'Guest details are required.',
            'guest_details.min' => 'At least one guest detail entry is required.',
            
            'guest_details.*.guest_type_name.required' => 'Guest type is required.',
            'guest_details.*.guest_type_name.in' => 'Guest type must be Adult, Senior Citizen, PWD, or Child.',
            'guest_details.*.guest_count.required' => 'Guest count is required.',
            'guest_details.*.guest_count.min' => 'Guest count must be at least 1.',
            
            'facilities.*.facility_id.required_with' => 'Facility selection is required.',
            'facilities.*.facility_id.exists' => 'Selected facility does not exist.',
            'facilities.*.rate_id.required_with' => 'Rate is required for each facility.',
            'facilities.*.quantity.required_with' => 'Quantity is required for each facility.',
            
            // ❌ Payment validation messages removed - payment handled in Billing module
        ];
    }
}