<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\FacilityAvailable;
use App\Models\Facility;
use Carbon\Carbon;

class UpdateBookingRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // Guest Information
            'guest_name' => 'required|string|max:255',
            'contact_number' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            
            // Booking Dates & Times
            'check_in_date' => 'required|date',
            'check_in_time' => 'required',
            'check_out_date' => 'required|date|after:check_in_date',
            'check_out_time' => 'required',
            
            // Facilities
            'facilities' => 'required|array|min:1',
            'facilities.*.facility_id' => 'required|exists:facilities,id',
            'facilities.*.rate_id' => 'nullable|exists:rates,id',
            'facilities.*.quantity' => 'required|integer|min:1',
            'facilities.*.guest_count' => 'required|integer|min:1',
            'facilities.*.rate_price' => 'required|numeric|min:0',
            'facilities.*.subtotal' => 'required|numeric|min:0',
            
            // Third Party Services (Optional)
            'third_party_services' => 'nullable|array',
            'third_party_services.*.service_id' => 'required_with:third_party_services|exists:third_party_services,id',
            'third_party_services.*.quantity' => 'required_with:third_party_services|integer|min:1',
            'third_party_services.*.price' => 'required_with:third_party_services|numeric|min:0',
            'third_party_services.*.subtotal' => 'required_with:third_party_services|numeric|min:0',
            
            // Discount
            'discount_mode' => 'nullable|in:None,Seasonal,Manual',
            'discount_id' => 'nullable|exists:discounts,id',
            'manual_discount_amount' => 'nullable|numeric|min:0',
            
            // Notes
            'notes' => 'nullable|string',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Parse check-in and check-out datetime
            $checkInDateTime = Carbon::parse($this->check_in_date . ' ' . $this->check_in_time);
            $checkOutDateTime = Carbon::parse($this->check_out_date . ' ' . $this->check_out_time);

            // Validate datetime logic
            if ($checkOutDateTime <= $checkInDateTime) {
                $validator->errors()->add('check_out_date', 'Check-out must be after check-in time.');
            }

            // Get booking ID being updated
            $bookingId = $this->route('id'); // Assumes route is /api/bookings/{id}

            // Validate facilities
            if ($this->has('facilities')) {
                foreach ($this->facilities as $index => $facilityData) {
                    $facility = Facility::find($facilityData['facility_id']);
                    
                    if (!$facility) {
                        continue;
                    }

                    // ✅ Check if facility is active (not soft-deleted)
                    if ($facility->trashed()) {
                        $validator->errors()->add(
                            "facilities.{$index}.facility_id",
                            "Facility '{$facility->name}' is not available for booking."
                        );
                        continue;
                    }

                    // ✅ Check if facility has any units
                    if ($facility->quantity <= 0) {
                        $validator->errors()->add(
                            "facilities.{$index}.facility_id",
                            "Facility '{$facility->name}' has no available units."
                        );
                        continue;
                    }

                    // NEW (CORRECT):
                    $guestCount = $facilityData['guest_count'] ?? 0;
                    if ($guestCount > $facility->max_capacity) {  // ✅ Use max_capacity
                        $validator->errors()->add(
                            "facilities.{$index}.guest_count",
                            "Guest count ({$guestCount}) exceeds facility maximum capacity ({$facility->max_capacity}) per unit."
                        );
                    }

                    // ✅ Check available quantity (exclude current booking)
                    $requestedQuantity = $facilityData['quantity'] ?? 1;
                    
                    $availabilityRule = new FacilityAvailable(
                        $facility->id,
                        $checkInDateTime,
                        $checkOutDateTime,
                        $requestedQuantity,
                        $bookingId // ✅ Exclude this booking from availability check
                    );

                    $availabilityRule->validate(
                        "facilities.{$index}.facility_id",
                        $facility->id,
                        function($message) use ($validator, $index) {
                            $validator->errors()->add("facilities.{$index}.facility_id", $message);
                        }
                    );
                }
            }
        });
    }

    public function messages()
    {
        return [
            'guest_name.required' => 'Guest name is required.',
            'contact_number.required' => 'Contact number is required.',
            'check_in_date.required' => 'Check-in date is required.',
            'check_out_date.required' => 'Check-out date is required.',
            'check_out_date.after' => 'Check-out date must be after check-in date.',
            'facilities.required' => 'At least one facility must be selected.',
            'facilities.*.facility_id.required' => 'Facility is required.',
            'facilities.*.quantity.required' => 'Quantity is required.',
            'facilities.*.quantity.min' => 'Quantity must be at least 1.',
            'facilities.*.guest_count.required' => 'Guest count is required.',
        ];
    }
}