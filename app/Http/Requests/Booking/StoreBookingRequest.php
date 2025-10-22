<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\FacilityAvailable;
use App\Models\Facility;
use Carbon\Carbon;

class StoreBookingRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // Guest Information
            'guest_name' => 'required|string|min:2|max:255',
            'contact_number' => 'required|string|regex:/^09[0-9]{9}$/|max:20',
            'email' => 'nullable|email:rfc,dns|max:255',
            'address' => 'nullable|string|max:500',
            'number_of_guests' => 'required|integer|min:1|max:1000',
            
            // ✅ Check-in datetime with timezone awareness
            'check_in_datetime' => [
                'required',
                'date',
                'after_or_equal:now',
                'before:' . now()->addYears(2)->format('Y-m-d'),
            ],
            
            // Facilities
            'facilities' => 'required|array|min:1|max:50',
            'facilities.*.facility_id' => [
                'required',
                'integer',
                'exists:facilities,id',
                'distinct',  // ✅ Prevent duplicate facility IDs
            ],
            'facilities.*.rate_id' => 'required|integer|exists:rates,id',
            'facilities.*.duration_hours' => 'required|integer|min:1|max:720',
            'facilities.*.quantity' => 'required|integer|min:1|max:100',
            'facilities.*.base_amount' => 'required|numeric|min:0|max:9999999.99',
            
            // Third Party Services
            'third_party_services' => 'nullable|array|max:50',
            'third_party_services.*.service_name' => 'required|string|min:2|max:255',
            'third_party_services.*.amount' => 'required|numeric|min:0.01|max:9999999.99',
            
            // Payment
            'payment.payment_method' => 'required|string|min:2|max:50',
            'payment.amount_paid' => 'required|numeric|min:0.01|max:9999999.99',
            'payment.change_amount' => 'nullable|numeric|min:0|max:9999999.99',
            'payment.payment_reference' => 'nullable|string|max:255',
            'payment.notes' => 'nullable|string|max:1000',
            
            // Notes
            'special_requests' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    protected function prepareForValidation()
    {
        $data = [];
        
        if ($this->has('guest_name')) {
            $data['guest_name'] = trim(strip_tags($this->guest_name));
        }
        
        if ($this->has('contact_number')) {
            $data['contact_number'] = preg_replace('/[^0-9]/', '', $this->contact_number);
        }
        
        if ($this->has('email')) {
            $data['email'] = strtolower(trim($this->email));
        }
        
        // ✅ Ensure timezone consistency
        if ($this->has('check_in_datetime')) {
            try {
                // Parse and convert to app timezone
                $checkIn = Carbon::parse($this->check_in_datetime);
                $data['check_in_datetime'] = $checkIn->setTimezone(config('app.timezone'))->toDateTimeString();
            } catch (\Exception $e) {
                // Let validation handle invalid format
            }
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

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            
            // ✅ VALIDATE CHECK-IN DATETIME
            if (!$this->has('check_in_datetime')) {
                $validator->errors()->add('check_in_datetime', 'Check-in date and time is required.');
                return;
            }
            
            try {
                $checkIn = Carbon::parse($this->check_in_datetime);
                
                // ✅ Check if too far in future
                if ($checkIn->gt(now()->addYears(2))) {
                    $validator->errors()->add(
                        'check_in_datetime',
                        'Check-in date cannot be more than 2 years in the future.'
                    );
                }
                
                // ✅ Check if in the past (1 hour grace period)
                if ($checkIn->lt(now()->subHour())) {
                    $validator->errors()->add(
                        'check_in_datetime',
                        'Check-in date/time must be in the future.'
                    );
                }
                
            } catch (\Exception $e) {
                $validator->errors()->add('check_in_datetime', 'Invalid check-in date/time format.');
                return;
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
                            "Facility '{$facility->name}' is not available for booking."
                        );
                        continue;
                    }

                    // ✅ Validate quantity doesn't exceed total
                    $requestedQuantity = $facilityData['quantity'] ?? 1;
                    if ($requestedQuantity > $facility->quantity) {
                        $validator->errors()->add(
                            "facilities.{$index}.quantity",
                            "Requested quantity ({$requestedQuantity}) exceeds total units ({$facility->quantity}) for '{$facility->name}'."
                        );
                        continue;
                    }

                    // ✅ CALCULATE END DATETIME FROM DURATION
                    try {
                        $checkIn = Carbon::parse($this->check_in_datetime);
                        $durationHours = $facilityData['duration_hours'] ?? 24;
                        $checkOut = $checkIn->copy()->addHours($durationHours);
                        
                        // ✅ CHECK AVAILABILITY (will check against both walk-ins and bookings)
                        $availabilityRule = new FacilityAvailable(
                            $facility->id,
                            $checkIn,
                            $checkOut,
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
                        $validator->errors()->add(
                            "facilities.{$index}.facility_id",
                            "Error checking availability: " . $e->getMessage()
                        );
                    }
                }
            }
            
            // ✅ VALIDATE PAYMENT (MINIMUM DEPOSIT)
            if ($this->has('payment') && $this->has('facilities')) {
                $facilityTotal = 0;
                
                foreach ($this->facilities as $facilityData) {
                    $facilityTotal += $facilityData['base_amount'] ?? 0;
                }
                
                $servicesTotal = 0;
                if ($this->has('third_party_services')) {
                    foreach ($this->third_party_services as $service) {
                        $servicesTotal += $service['amount'] ?? 0;
                    }
                }
                
                $totalAmount = $facilityTotal + $servicesTotal;
                $minimumDeposit = $totalAmount * 0.50;  // 50% deposit required
                $amountPaid = $this->input('payment.amount_paid', 0);
                
                // ✅ Check minimum deposit
                if ($amountPaid < $minimumDeposit) {
                    $validator->errors()->add(
                        'payment.amount_paid',
                        sprintf(
                            'Minimum deposit of ₱%.2f (50%% of ₱%.2f) is required. You paid ₱%.2f.',
                            $minimumDeposit,
                            $totalAmount,
                            $amountPaid
                        )
                    );
                }
                
                // ✅ Check overpayment
                if ($amountPaid > $totalAmount + 1000) {
                    $validator->errors()->add(
                        'payment.amount_paid',
                        sprintf(
                            'Payment amount (₱%.2f) exceeds total amount (₱%.2f).',
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
            'contact_number.required' => 'Contact number is required.',
            'contact_number.regex' => 'Contact number must be a valid Philippine mobile number (09XXXXXXXXX).',
            'email.email' => 'Please provide a valid email address.',
            'check_in_datetime.required' => 'Check-in date and time is required.',
            'check_in_datetime.after_or_equal' => 'Check-in must be in the future.',
            'number_of_guests.required' => 'Number of guests is required.',
            'number_of_guests.min' => 'At least 1 guest is required.',
            'facilities.required' => 'At least one facility must be selected.',
            'facilities.min' => 'At least one facility must be selected.',
            'facilities.*.facility_id.required' => 'Facility is required.',
            'facilities.*.facility_id.exists' => 'Selected facility does not exist.',
            'facilities.*.rate_id.required' => 'Rate is required.',
            'facilities.*.quantity.required' => 'Quantity is required.',
            'facilities.*.quantity.min' => 'Quantity must be at least 1.',
            'payment.payment_method.required' => 'Payment method is required.',
            'payment.amount_paid.required' => 'Payment amount is required.',
            'payment.amount_paid.min' => 'Payment amount must be greater than zero.',
        ];
    }
}