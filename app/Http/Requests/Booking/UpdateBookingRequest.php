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
        $booking = \App\Models\Booking::find($this->route('id'));
        
        // ✅ For Confirmed bookings, only allow contact details
        if ($booking && $booking->booking_status === 'Confirmed') {
            return [
                'guest_name' => 'required|string|min:2|max:255',
                'contact_number' => 'required|string|max:20',
                'special_requests' => 'nullable|string|max:1000',
            ];
        }
        
        // ✅ Full validation for Pending bookings only
        return [
            // ✅ BOOKING TYPE
            'booking_type' => 'required|in:Swimming,Package',
            
            // ✅ ENTRANCE RATE (required for Swimming)
            'entrance_rate_id' => 'nullable|required_if:booking_type,Swimming|exists:rates,id',
            
            // ✅ GUEST INFORMATION
            'guest_name' => 'required|string|min:2|max:255',
            'contact_number' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'number_of_guests' => 'required|integer|min:1',
            
            // ✅ CHECK-IN/OUT DATES
            'check_in_date' => 'required|date|date_format:Y-m-d',
            'check_out_date' => 'required|date|date_format:Y-m-d|after_or_equal:check_in_date',
            'check_in_time' => 'nullable|date_format:H:i',
            'check_out_time' => 'nullable|date_format:H:i',
            
            // ✅ FACILITIES (REQUIRED)
            'facilities' => 'required|array|min:1',
            'facilities.*.facility_id' => 'required|integer|exists:facilities,id',
            'facilities.*.rate_id' => 'required|integer|exists:rates,id',
            'facilities.*.quantity' => 'required|integer|min:1',
            'facilities.*.rate_amount' => 'required|numeric|min:0',
            
            // ✅ DISCOUNTS
            'discount_mode' => 'nullable|in:None,Direct,Seasonal,Manual',
            'discount_id' => 'nullable|exists:discounts,id',
            'manual_discount_amount' => 'nullable|numeric|min:0',
            
            // ✅ GUEST DISCOUNTS (for Direct mode)
            'guest_discounts' => 'nullable|array',
            'guest_discounts.*.guest_type' => 'required_with:guest_discounts|string|in:senior,child',
            'guest_discounts.*.count' => 'required_with:guest_discounts|integer|min:1',
            'guest_discounts.*.discount_id' => 'required_with:guest_discounts|exists:discounts,id',
            
            // ✅ THIRD PARTY SERVICES (Optional)
            'third_party_services' => 'nullable|array',
            'third_party_services.*.service_name' => 'required_with:third_party_services|string|max:255',
            'third_party_services.*.amount' => 'required_with:third_party_services|numeric|min:0',
            
            // ✅ NOTES
            'special_requests' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Get booking ID being updated
            $bookingId = $this->route('id');
            $booking = \App\Models\Booking::find($bookingId);
            
            // ✅ Skip complex validation for Confirmed bookings (only contact details allowed)
            if ($booking && $booking->booking_status === 'Confirmed') {
                return;
            }
            
            // ✅ Full validation only for Pending bookings
            // Only validate datetime logic if both times are provided
            if ($this->check_in_time && $this->check_out_time) {
                try {
                    $checkInDateTime = Carbon::parse($this->check_in_date . ' ' . $this->check_in_time);
                    $checkOutDateTime = Carbon::parse($this->check_out_date . ' ' . $this->check_out_time);

                    if ($checkOutDateTime <= $checkInDateTime) {
                        $validator->errors()->add('check_out_date', 'Check-out must be after check-in time.');
                    }
                } catch (\Exception $e) {
                    // Time parsing will be caught by format validation
                }
            }

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

                    // ✅ REMOVED: guest_count validation - not used in update
                    // The update doesn't use guest_count per facility
                    
                    // ✅ Check available quantity (exclude current booking) - only if times provided
                    if ($this->check_in_time && $this->check_out_time) {
                        try {
                            $checkInDateTime = Carbon::parse($this->check_in_date . ' ' . $this->check_in_time);
                            $checkOutDateTime = Carbon::parse($this->check_out_date . ' ' . $this->check_out_time);
                            
                            $requestedQuantity = $facilityData['quantity'] ?? 1;
                            
                            $availabilityRule = new FacilityAvailable(
                                $facility->id,
                                $checkInDateTime,
                                $checkOutDateTime,
                                $requestedQuantity,
                                $bookingId
                            );

                            $availabilityRule->validate(
                                "facilities.{$index}.facility_id",
                                $facility->id,
                                function($message) use ($validator, $index) {
                                    $validator->errors()->add("facilities.{$index}.facility_id", $message);
                                }
                            );
                        } catch (\Exception $e) {
                            // Skip availability check if time parsing fails
                        }
                    }
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