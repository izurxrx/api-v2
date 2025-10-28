<?php

namespace App\Http\Requests\GuestEntry;

use App\Models\Rate;
use Illuminate\Foundation\Http\FormRequest;
use App\Rules\FacilityAvailable;
use App\Models\Facility;
use App\Models\Discount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class StoreGuestEntryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // Entry Information
            'entry_date' => 'required|date|date_format:Y-m-d|before_or_equal:today|after:' . now()->subDays(7)->format('Y-m-d'),
            'entry_time' => 'required|date_format:H:i',
            'guest_name' => 'required|string|min:2|max:255',
            'contact_number' => 'required|string|regex:/^09[0-9]{9}$/|max:20',
            'total_guests' => 'required|integer|min:1|max:1000',
            
            // Guest Details (entrance fees)
            'details' => 'required|array|min:1|max:50',
            'details.*.guest_type_name' => 'required|string|min:2|max:100',
            'details.*.rate_id' => 'required|integer|exists:rates,id',
            'details.*.guest_count' => 'required|integer|min:1|max:1000',
            'details.*.discount_mode' => 'nullable|in:Direct,None',
            'details.*.discount_id' => 'nullable|integer|exists:discounts,id',
            
            // Facilities
            'facilities' => 'required|array|min:1|max:50',
            'facilities.*.facility_id' => 'required|integer|exists:facilities,id',
            'facilities.*.rate_id' => 'required|integer|exists:rates,id',
            'facilities.*.quantity' => 'required|integer|min:1|max:100',
            'facilities.*.guest_count' => 'required|integer|min:1|max:1000',
            'facilities.*.rate_price' => 'required|numeric|min:0|max:9999999.99',
            'facilities.*.subtotal' => 'required|numeric|min:0|max:9999999.99',
            'facilities.*.start_datetime' => 'required|date_format:Y-m-d H:i:s',
            'facilities.*.end_datetime' => 'required|date_format:Y-m-d H:i:s',
            
            // Third Party Services (Optional)
            'third_party_services' => 'nullable|array|max:50',
            'third_party_services.*.service_name' => 'required|string|min:2|max:255',
            'third_party_services.*.amount' => 'required|numeric|min:0.01|max:9999999.99',
            
            // Discount
            'discount_mode' => 'required|in:None,Seasonal,Manual,Direct',
            'discount_id' => 'nullable|integer|exists:discounts,id',
            'manual_discount_amount' => 'nullable|numeric|min:0|max:9999999.99',
            
            // Payment
            'payment.payment_method' => 'required|string|min:2|max:50',
            'payment.amount_paid' => 'required|numeric|min:0.01|max:9999999.99',
            'payment.change_amount' => 'nullable|numeric|min:0|max:9999999.99',
            'payment.payment_reference' => 'nullable|string|max:255',
            'payment.notes' => 'nullable|string|max:1000',
            
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            
            // ✅ VALIDATE ENTRY DATE/TIME
            try {
                $entryDate = Carbon::parse($this->entry_date);
                $entryTime = $this->entry_time ?? '00:00';
                $entryDateTime = Carbon::parse("{$entryDate->format('Y-m-d')} {$entryTime}");
                
                // ✅ USE ENTRY DATE (not "today") for availability check
                $startOfDay = $entryDate->copy()->startOfDay();
                $endOfDay = $entryDate->copy()->endOfDay();
                
                // ✅ Check if entry is too old (max 7 days backdating)
                if ($entryDateTime->lt(now()->subDays(7))) {
                    $validator->errors()->add(
                        'entry_date',
                        'Cannot create entries older than 7 days.'
                    );
                }
                
                // ✅ Check if entry is in future (walk-ins should be same-day or backdated)
                if ($entryDateTime->gt(now()->addHours(2))) {
                    $validator->errors()->add(
                        'entry_date',
                        'Walk-in entries cannot be scheduled in the future. Please use the booking system.'
                    );
                }
                
            } catch (\Exception $e) {
                $validator->errors()->add('entry_date', 'Invalid entry date/time format.');
                return;
            }
            
            // ✅ VALIDATE TOTAL GUESTS MATCHES DETAILS
            if ($this->has('details') && $this->has('total_guests')) {
                $detailsTotal = array_sum(array_column($this->details, 'guest_count'));
                if ($detailsTotal != $this->total_guests) {
                    $validator->errors()->add(
                        'total_guests',
                        "Total guests ({$this->total_guests}) doesn't match sum of guest details ({$detailsTotal})."
                    );
                }
            }
            
            // ✅ CHECK FOR DUPLICATE FACILITIES
            if ($this->has('facilities')) {
                $facilityIds = array_column($this->facilities, 'facility_id');
                $duplicates = array_diff_assoc($facilityIds, array_unique($facilityIds));
                
                if (!empty($duplicates)) {
                    $validator->errors()->add(
                        'facilities',
                        'Duplicate facilities detected. Each facility can only be added once.'
                    );
                }
            }

            // ✅ VALIDATE FACILITIES
            if ($this->has('facilities')) {
                foreach ($this->facilities as $index => $facilityData) {
                    $facility = Facility::find($facilityData['facility_id']);
                    
                    if (!$facility) {
                        $validator->errors()->add(
                            "facilities.{$index}.facility_id",
                            'Invalid facility selected.'
                        );
                        continue;
                    }

                    // ✅ Check maintenance
                    if ($facility->is_maintenance) {
                        $validator->errors()->add(
                            "facilities.{$index}.facility_id",
                            "Facility '{$facility->name}' is currently under maintenance."
                        );
                        continue;
                    }

                    // ✅ Check available for booking
                    if (!$facility->is_available_for_booking) {
                        $validator->errors()->add(
                            "facilities.{$index}.facility_id",
                            "Facility '{$facility->name}' is not available for walk-in."
                        );
                        continue;
                    }

                    // ✅ Validate facility type (walk-ins only)
                    if ($facility->booking_type === 'booking') {
                        $validator->errors()->add(
                            "facilities.{$index}.facility_id",
                            "Facility '{$facility->name}' requires advance booking. Please use the booking system."
                        );
                        continue;
                    }

                    // ✅ VALIDATE QUANTITY DOESN'T EXCEED TOTAL
                    $requestedQuantity = $facilityData['quantity'] ?? 1;
                    if ($requestedQuantity > $facility->quantity) {
                        $validator->errors()->add(
                            "facilities.{$index}.quantity",
                            "Requested quantity ({$requestedQuantity}) exceeds total units ({$facility->quantity}) for '{$facility->name}'."
                        );
                        continue;
                    }

                    // ✅ Check availability (walk-ins occupy full day)
                    try {
                        $availabilityRule = new FacilityAvailable(
                            $facility->id,
                            $startOfDay,
                            $endOfDay,
                            $requestedQuantity
                        );

                        $availabilityRule->validate(
                            "facilities.{$index}.facility_id",
                            $facility->id,
                            function($message) use ($validator, $index) {
                                $validator->errors()->add("facilities.{$index}.facility_id", $message);
                            }
                        );
                    } catch (\Exception $e) {
                        Log::error('Facility availability check failed', [
                            'facility_id' => $facility->id,
                            'error' => $e->getMessage()
                        ]);
                        $validator->errors()->add(
                            "facilities.{$index}.facility_id",
                            "Error checking availability. Please try again."
                        );
                    }
                }
            }

            // ✅ VALIDATE DISCOUNT
            if ($this->discount_mode === 'Seasonal' && $this->discount_id) {
                $discount = Discount::find($this->discount_id);
                
                if (!$discount) {
                    $validator->errors()->add('discount_id', 'Selected discount does not exist.');
                } else {
                    // ✅ Check if discount is active
                    if (!$discount->is_active) {
                        $validator->errors()->add('discount_id', 'Selected discount is not active.');
                    }
                    
                    // ✅ Check if discount is valid for today
                    $today = now()->format('Y-m-d');
                    if ($discount->valid_from && $today < $discount->valid_from) {
                        $validator->errors()->add('discount_id', 'Discount is not yet valid.');
                    }
                    if ($discount->valid_until && $today > $discount->valid_until) {
                        $validator->errors()->add('discount_id', 'Discount has expired.');
                    }
                    
                }
            }

            // ✅ VALIDATE PAYMENT (FULL PAYMENT REQUIRED FOR WALK-INS)
            if ($this->has('payment') && $this->has('facilities')) {
                $facilityTotal = array_sum(array_column($this->facilities, 'subtotal'));
                
                $entranceTotal = 0;
                if ($this->has('details')) {
                    foreach ($this->details as $detail) {
                        $rate = Rate::find($detail['rate_id']);
                        if ($rate) {
                            $subtotal = $rate->base_price * $detail['guest_count'];
                            
                            // Apply direct discount if any
                            if (($detail['discount_mode'] ?? 'None') === 'Direct' && isset($detail['discount_id'])) {
                                $discount = Discount::find($detail['discount_id']);
                                if ($discount && $discount->is_active) {
                                    if ($discount->type === 'Percentage') {
                                        $subtotal -= ($subtotal * $discount->value / 100);
                                    } else {
                                        $subtotal -= min($discount->value, $subtotal);
                                    }
                                }
                            }
                            
                            $entranceTotal += $subtotal;
                        }
                    }
                }
                
                // Apply seasonal/manual discount
                $discountAmount = 0;
                if ($this->discount_mode === 'Seasonal' && $this->discount_id) {
                    $discount = Discount::where('id', $this->discount_id)
                        ->where('is_active', true)
                        ->first();
                        
                    if ($discount) {
                        if ($discount->type === 'Percentage') {
                            $discountAmount = ($entranceTotal * $discount->value / 100);
                        } else {
                            $discountAmount = min($discount->value, $entranceTotal);
                        }
                    }
                } elseif ($this->discount_mode === 'Manual') {
                    $discountAmount = min(
                        $this->manual_discount_amount ?? 0,
                        $entranceTotal + $facilityTotal  // Can't discount more than total
                    );
                }
                
                $servicesTotal = 0;
                if ($this->has('third_party_services')) {
                    $servicesTotal = array_sum(array_column($this->third_party_services, 'amount'));
                }
                
                $totalAmount = $entranceTotal + $facilityTotal + $servicesTotal - $discountAmount;
                $amountPaid = $this->input('payment.amount_paid', 0);
                
                // ✅ CRITICAL: Full payment required for walk-ins
                $tolerance = 1.00;  // Allow ₱1 tolerance for rounding
                if ($amountPaid < ($totalAmount - $tolerance)) {
                    $validator->errors()->add(
                        'payment.amount_paid', 
                        sprintf(
                            'Full payment of ₱%.2f is required for walk-in entries. You paid ₱%.2f (₱%.2f short).',
                            $totalAmount,
                            $amountPaid,
                            $totalAmount - $amountPaid
                        )
                    );
                }
                
                // ✅ Validate overpayment
                if ($amountPaid > $totalAmount + 10000) {
                    $validator->errors()->add(
                        'payment.amount_paid',
                        sprintf(
                            'Payment amount (₱%.2f) is too high. Total is ₱%.2f.',
                            $amountPaid,
                            $totalAmount
                        )
                    );
                }
            }
        });
    }

    public function messages()
    {
        return [
            'guest_name.required' => 'Guest name is required.',
            'guest_name.min' => 'Guest name must be at least 2 characters.',
            
            'contact_number.regex' => 'Contact number must be a valid Philippine mobile number (09XXXXXXXXX).',
            
            'entry_date.required' => 'Entry date is required.',
            'entry_date.before_or_equal' => 'Entry date cannot be in the future.',
            
            'total_guest.required' => 'Number of guests is required.',
            'total_guest.min' => 'At least 1 guest is required.',
            
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
            
            'payment.required' => 'Payment information is required.',
            'payment.payment_method.required' => 'Payment method is required.',
            'payment.amount_paid.required' => 'Payment amount is required.',
        ];
    }
    
    /**
     * ✅ Sanitize input data
     */
    protected function prepareForValidation()
    {
        $data = [];
        
        if ($this->has('guest_name')) {
            $data['guest_name'] = trim(strip_tags($this->guest_name));
        }
        
        if ($this->has('contact_number')) {
            $data['contact_number'] = preg_replace('/[^0-9]/', '', $this->contact_number);
        }
        
        // ✅ Sanitize service names
        if ($this->has('third_party_services')) {
            $services = [];
            foreach ($this->third_party_services as $service) {
                $services[] = [
                    'service_name' => trim(strip_tags($service['service_name'] ?? '')),
                    'amount' => $service['amount'] ?? 0,
                ];
            }
            $data['third_party_services'] = $services;
        }
        
        $this->merge($data);
    }
}