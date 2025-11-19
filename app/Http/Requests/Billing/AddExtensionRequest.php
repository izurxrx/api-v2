<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Rate;
use App\Models\Discount;
use App\Models\Facility;

class AddExtensionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * Auto-determine discount mode from actual values provided
     * Same logic as StoreBookingRequest
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
            // ✅ FACILITIES (optional - for facility extensions)
            'facilities' => 'nullable|array|max:50',
            'facilities.*.facility_id' => [
                'required_with:facilities',
                'integer',
                'exists:facilities,id',
            ],
            'facilities.*.rate_id' => 'required_with:facilities|integer|exists:rates,id',
            'facilities.*.quantity' => 'required_with:facilities|integer|min:1|max:100',
            'facilities.*.hours' => 'nullable|numeric|min:0.5|max:24', // For hourly rates
            'facilities.*.rate_amount' => 'nullable|numeric|min:0|max:9999999.99', // Optional - will be fetched from rate
            
            // ✅ GUEST CHARGES (optional - for adding guests with discounts)
            'guest_charges' => 'nullable|array|max:50',
            'guest_charges.*.guest_type' => 'required_with:guest_charges|string|in:adult,senior,child,infant',
            'guest_charges.*.count' => 'required_with:guest_charges|integer|min:1|max:100',
            'guest_charges.*.rate_per_guest' => 'required_with:guest_charges|numeric|min:0|max:9999999.99',
            'guest_charges.*.discount_id' => 'nullable|exists:discounts,id',
            
            // ✅ DISCOUNT MODE (optional - auto-determined from actual values)
            'discount_mode' => 'nullable|in:None,Direct,Seasonal,Manual',
            'discount_id' => 'nullable|exists:discounts,id', // For Seasonal discount
            'manual_discount_amount' => 'nullable|numeric|min:0|max:9999999.99',
            
            // ✅ GUEST DISCOUNTS (alternative to guest_charges - per-guest Direct discounts)
            'guest_discounts' => 'nullable|array',
            'guest_discounts.*.guest_type' => 'required_with:guest_discounts|string|in:senior,child',
            'guest_discounts.*.count' => 'required_with:guest_discounts|integer|min:1',
            'guest_discounts.*.discount_id' => 'required_with:guest_discounts|exists:discounts,id',
            
            // ✅ THIRD PARTY SERVICES (optional)
            'third_party_services' => 'nullable|array|max:50',
            'third_party_services.*.service_name' => 'required_with:third_party_services|string|max:255',
            'third_party_services.*.amount' => 'required_with:third_party_services|numeric|min:0|max:9999999.99',
            
            // ✅ SIMPLE MODE (for damage/service charges without facility selection)
            'extension_type' => 'nullable|in:facility,guest,damage,service',
            'description' => 'nullable|string|max:500',
            'amount' => 'nullable|numeric|min:0|max:9999999.99', // Used in simple mode
            'quantity' => 'nullable|integer|min:1|max:1000', // Used in simple mode
            
            // ✅ PAYMENT (optional - immediate payment)
            'payment_required' => 'boolean',
            'payment_amount' => 'nullable|numeric|min:0|max:9999999.99',
            'payment_method' => 'nullable|required_with:payment_amount|string|in:Cash,Card,GCash,Bank Transfer,PayMaya,Other',
            
            // ✅ METADATA (optional - for extra details)
            'metadata' => 'nullable|array',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // ✅ Must provide at least one extension type
            $hasFacilities = !empty($this->facilities);
            $hasGuestCharges = !empty($this->guest_charges) || !empty($this->guest_discounts);
            $hasServices = !empty($this->third_party_services);
            $hasSimpleMode = $this->has('amount') && $this->amount > 0;
            
            if (!$hasFacilities && !$hasGuestCharges && !$hasServices && !$hasSimpleMode) {
                $validator->errors()->add('facilities', 
                    'Extension must include at least one of: facilities, guest_charges, third_party_services, or amount.');
            }
            
            // ✅ Validate facility rates match facilities
            if ($this->has('facilities')) {
                foreach ($this->facilities as $index => $facilityData) {
                    $rate = Rate::find($facilityData['rate_id']);
                    if ($rate && $rate->facility_id != $facilityData['facility_id']) {
                        $validator->errors()->add("facilities.{$index}.rate_id", 
                            'Selected rate does not belong to the selected facility.');
                    }
                }
            }
            
            // ✅ Validate guest discounts are Direct category
            if ($this->has('guest_discounts')) {
                foreach ($this->guest_discounts as $index => $guestDiscount) {
                    $discount = Discount::find($guestDiscount['discount_id']);
                    if ($discount && $discount->category !== 'Direct_Discount') {
                        $validator->errors()->add("guest_discounts.{$index}.discount_id", 
                            'Only Direct discounts can be applied per guest.');
                    }
                    if ($discount && !$discount->is_active) {
                        $validator->errors()->add("guest_discounts.{$index}.discount_id", 
                            'Selected discount is not active.');
                    }
                }
            }
            
            // ✅ Validate seasonal discount
            if ($this->discount_id) {
                $discount = Discount::find($this->discount_id);
                if ($discount && $discount->category !== 'Seasonal_Discount') {
                    $validator->errors()->add('discount_id', 
                        'Selected discount must be a Seasonal discount.');
                }
                if ($discount && !$discount->is_active) {
                    $validator->errors()->add('discount_id', 
                        'Selected discount is not active.');
                }
            }
            
            // ✅ Validate guest_charges discounts are Direct category
            if ($this->has('guest_charges')) {
                foreach ($this->guest_charges as $index => $guestCharge) {
                    if (isset($guestCharge['discount_id']) && $guestCharge['discount_id']) {
                        $discount = Discount::find($guestCharge['discount_id']);
                        if ($discount && $discount->category !== 'Direct_Discount') {
                            $validator->errors()->add("guest_charges.{$index}.discount_id", 
                                'Only Direct discounts can be applied per guest.');
                        }
                    }
                }
            }
            
            // ✅ Validate payment amount doesn't exceed extension total (if we can calculate it)
            if ($this->payment_amount && $this->has('amount') && $this->quantity) {
                $simpleTotal = $this->amount * $this->quantity;
                if ($this->payment_amount > $simpleTotal) {
                    $validator->errors()->add('payment_amount', 
                        'Payment amount cannot exceed extension total.');
                }
            }
        });
    }

    /**
     * Prepare data before validation
     */
    protected function prepareForValidation()
    {
        $data = [];
        
        // Set default quantity to 1 if not provided
        if ($this->has('amount') && !$this->has('quantity')) {
            $data['quantity'] = 1;
        }
        
        // Set payment_required to false if not provided
        if (!$this->has('payment_required')) {
            $data['payment_required'] = false;
        }
        
        $this->merge($data);
    }
}
