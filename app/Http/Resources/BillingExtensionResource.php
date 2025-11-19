<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingExtensionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'billing_id' => $this->billing_id,
            'extension_type' => $this->extension_type,
            'is_overtime' => $this->is_overtime,
            'description' => $this->description,
            
            // Facility details (for overtime)
            'facility' => $this->when($this->facility_id, function() {
                return [
                    'id' => $this->facility_id,
                    'name' => $this->facility->name ?? 'N/A',
                ];
            }),
            
            // Rate details (for overtime)
            'rate' => $this->when($this->rate_id, function() {
                return [
                    'id' => $this->rate_id,
                    'name' => $this->rate->rate_name ?? 'N/A',
                    'extension_fee' => $this->rate->extension_fee ?? 0,
                ];
            }),
            
            // Discount details (if applied)
            'discount' => $this->when($this->discount_id, function() {
                return [
                    'id' => $this->discount_id,
                    'name' => $this->discount->name ?? 'N/A',
                    'amount' => $this->discount_amount,
                ];
            }),
            
            // Time tracking (for facilities with extensions)
            'facility_start_datetime' => $this->facility_start_datetime?->format('Y-m-d H:i:s'),
            'facility_end_datetime' => $this->facility_end_datetime?->format('Y-m-d H:i:s'),
            
            // Amounts
            'amount' => $this->amount,
            'hours' => $this->hours,
            'quantity' => $this->quantity,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            
            // Formatted amounts
            'formatted_amount' => '₱' . number_format($this->amount, 2),
            'formatted_discount' => $this->discount_amount > 0 ? '₱' . number_format($this->discount_amount, 2) : null,
            'formatted_total' => '₱' . number_format($this->total_amount, 2),
            
            // Metadata
            'metadata' => $this->metadata,
            
            // Audit
            'added_by' => new UserResource($this->whenLoaded('addedBy')),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
