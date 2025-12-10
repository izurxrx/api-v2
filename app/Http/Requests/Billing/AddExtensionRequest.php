<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Rate;
use App\Models\Facility;

class AddExtensionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * ✅ SIMPLIFIED: Removed all discount logic
     * Extensions are simple additions without discount complexity
     * Discounts are only applied at creation time (walk-in/booking)
     */
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
            'facilities.*.hours' => 'nullable|numeric|min:0.5|max:24',
            
            // ✅ GUEST CHARGES (optional - for adding guests)
            'guest_charges' => 'nullable|array|max:50',
            'guest_charges.*.guest_type' => 'required_with:guest_charges|string|in:adult,senior,child,infant',
            'guest_charges.*.count' => 'required_with:guest_charges|integer|min:1|max:100',
            'guest_charges.*.rate_per_guest' => 'nullable|numeric|min:0|max:9999999.99',
            
            // ✅ THIRD PARTY SERVICES (optional)
            'third_party_services' => 'nullable|array|max:50',
            'third_party_services.*.service_name' => 'required_with:third_party_services|string|max:255',
            'third_party_services.*.amount' => 'required_with:third_party_services|numeric|min:0|max:9999999.99',
            
            // ✅ SIMPLE MODE (for damage/service charges)
            'extension_type' => 'nullable|in:facility,guest,damage,service',
            'description' => 'nullable|string|max:500',
            'amount' => 'nullable|numeric|min:0|max:9999999.99',
            'quantity' => 'nullable|integer|min:1|max:1000',
            
            // ✅ PAYMENT (optional - immediate payment)
            'payment_required' => 'boolean',
            'payment_amount' => 'nullable|numeric|min:0|max:9999999.99',
            'payment_method' => 'nullable|required_with:payment_amount|string|in:Cash,Card,GCash,Bank Transfer,PayMaya,Other',
            
            // ✅ METADATA (optional)
            'metadata' => 'nullable|array',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // ❌ REJECT if discount fields are sent
            if ($this->has('discount_id') && !empty($this->discount_id)) {
                $validator->errors()->add('discount_id', 
                    'Discounts cannot be applied to extensions. Discounts are set at creation time.');
            }
            
            if ($this->has('discount_mode') && !empty($this->discount_mode) && $this->discount_mode !== 'None') {
                $validator->errors()->add('discount_mode', 
                    'Discounts cannot be applied to extensions. Discounts are set at creation time.');
            }
            
            if ($this->has('manual_discount_amount') && !empty($this->manual_discount_amount) && $this->manual_discount_amount > 0) {
                $validator->errors()->add('manual_discount_amount', 
                    'Discounts cannot be applied to extensions. Discounts are set at creation time.');
            }
            
            if ($this->has('guest_discounts') && !empty($this->guest_discounts)) {
                $validator->errors()->add('guest_discounts', 
                    'Discounts cannot be applied to extensions. Discounts are set at creation time.');
            }
            
            // ✅ Must provide at least one extension type
            $hasFacilities = !empty($this->facilities);
            $hasGuestCharges = !empty($this->guest_charges);
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
            
            // ✅ Validate payment amount
            if ($this->payment_amount && $this->has('amount') && $this->quantity) {
                $simpleTotal = $this->amount * $this->quantity;
                if ($this->payment_amount > $simpleTotal) {
                    $validator->errors()->add('payment_amount', 
                        'Payment amount cannot exceed extension total.');
                }
            }
        });
    }

    protected function prepareForValidation()
    {
        $data = [];
        
        if ($this->has('amount') && !$this->has('quantity')) {
            $data['quantity'] = 1;
        }
        
        if (!$this->has('payment_required')) {
            $data['payment_required'] = false;
        }
        
        $this->merge($data);
    }
}
