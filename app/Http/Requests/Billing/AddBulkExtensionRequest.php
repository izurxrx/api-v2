<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Rate;
use App\Models\Facility;

class AddBulkExtensionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * Bulk extensions endpoint validation
     * Accepts multiple extensions in a single request for atomic processing
     */
    public function rules()
    {
        return [
            // ✅ EXTENSIONS ARRAY (required, 1-20 items)
            'extensions' => 'required|array|min:1|max:20',
            
            // Facility extensions
            'extensions.*.facilities' => 'nullable|array|max:10',
            'extensions.*.facilities.*.facility_id' => 'required_with:extensions.*.facilities|integer|exists:facilities,id',
            'extensions.*.facilities.*.rate_id' => 'required_with:extensions.*.facilities|integer|exists:rates,id',
            'extensions.*.facilities.*.quantity' => 'required_with:extensions.*.facilities|integer|min:1|max:100',
            'extensions.*.facilities.*.hours' => 'nullable|numeric|min:0.5|max:24',
            
            // Guest charges
            'extensions.*.guest_charges' => 'nullable|array|max:10',
            'extensions.*.guest_charges.*.guest_type' => 'required_with:extensions.*.guest_charges|string|in:adult,senior,child,infant',
            'extensions.*.guest_charges.*.count' => 'required_with:extensions.*.guest_charges|integer|min:1|max:100',
            'extensions.*.guest_charges.*.rate_per_guest' => 'nullable|numeric|min:0|max:9999999.99',
            
            // Third-party services
            'extensions.*.third_party_services' => 'nullable|array|max:10',
            'extensions.*.third_party_services.*.service_name' => 'required_with:extensions.*.third_party_services|string|max:255',
            'extensions.*.third_party_services.*.amount' => 'required_with:extensions.*.third_party_services|numeric|min:0|max:9999999.99',
            
            // Simple mode (damage/custom charges)
            'extensions.*.simple_mode' => 'nullable|boolean',
            'extensions.*.amount' => 'nullable|numeric|min:0|max:9999999.99',
            'extensions.*.quantity' => 'nullable|integer|min:1|max:1000',
            
            // ✅ OPTIONAL PAYMENT (after all extensions)
            'amount_paid' => 'nullable|numeric|min:0|max:9999999.99',
            'payment_method' => 'nullable|required_with:amount_paid|string|in:Cash,Card,GCash,Bank Transfer,PayMaya,Other',
            'payment_notes' => 'nullable|string|max:500',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // ❌ REJECT if discount fields are sent
            if ($this->has('discount_id') || $this->has('discount_mode') || 
                $this->has('manual_discount_amount') || $this->has('guest_discounts')) {
                $validator->errors()->add('extensions', 
                    'Discounts cannot be applied to extensions. Discounts are only set at booking/entry creation time.');
            }
            
            // Validate each extension has at least one type
            if ($this->has('extensions')) {
                foreach ($this->extensions as $index => $extension) {
                    $hasFacilities = !empty($extension['facilities']);
                    $hasGuestCharges = !empty($extension['guest_charges']);
                    $hasServices = !empty($extension['third_party_services']);
                    $hasSimpleMode = !empty($extension['simple_mode']) && isset($extension['amount']) && $extension['amount'] > 0;
                    
                    if (!$hasFacilities && !$hasGuestCharges && !$hasServices && !$hasSimpleMode) {
                        $validator->errors()->add("extensions.{$index}", 
                            'Each extension must include at least one of: facilities, guest_charges, third_party_services, or simple_mode with amount.');
                    }
                    
                    // Validate facility rates match facilities
                    if (isset($extension['facilities'])) {
                        foreach ($extension['facilities'] as $fIndex => $facilityData) {
                            $rate = Rate::find($facilityData['rate_id']);
                            if ($rate && $rate->facility_id != $facilityData['facility_id']) {
                                $validator->errors()->add("extensions.{$index}.facilities.{$fIndex}.rate_id", 
                                    'Selected rate does not belong to the selected facility.');
                            }
                        }
                    }
                }
            }
        });
    }

    public function messages()
    {
        return [
            'extensions.required' => 'At least one extension is required.',
            'extensions.min' => 'At least one extension is required.',
            'extensions.max' => 'Maximum 20 extensions allowed per request.',
            'amount_paid.max' => 'Payment amount is too large.',
            'payment_method.required_with' => 'Payment method is required when recording a payment.',
        ];
    }
}
