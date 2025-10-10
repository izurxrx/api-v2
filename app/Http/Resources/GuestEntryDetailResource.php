<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestEntryDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'guest_entry_id' => $this->guest_entry_id,
            'rate_id' => $this->rate_id,
            'guest_type_id' => $this->guest_type_id,
            'guest_count' => $this->guest_count,
            'base_rate' => $this->base_rate,
            'auto_discount_id' => $this->auto_discount_id,
            'auto_discount_amount' => $this->auto_discount_amount,
            'manual_discount_id' => $this->manual_discount_id,
            'manual_discount_amount' => $this->manual_discount_amount,
            'final_rate' => $this->final_rate,
            'total_amount' => $this->total_amount,
            
            // Formatted amounts
            'formatted_base_rate' => '₱' . number_format($this->base_rate, 2),
            'formatted_auto_discount' => $this->auto_discount_amount > 0 
                ? '₱' . number_format($this->auto_discount_amount, 2) 
                : null,
            'formatted_manual_discount' => $this->manual_discount_amount > 0 
                ? '₱' . number_format($this->manual_discount_amount, 2) 
                : null,
            'formatted_final_rate' => '₱' . number_format($this->final_rate, 2),
            'formatted_total_amount' => '₱' . number_format($this->total_amount, 2),
            
            // Calculated fields
            'total_discount_amount' => $this->auto_discount_amount + $this->manual_discount_amount,
            'formatted_total_discount' => '₱' . number_format($this->auto_discount_amount + $this->manual_discount_amount, 2),
            'discount_percentage' => $this->base_rate > 0 
                ? round((($this->auto_discount_amount + $this->manual_discount_amount) / $this->base_rate) * 100, 2)
                : 0,
            
            // Line item description for receipts
            'line_description' => $this->getLineDescription(),
            
            // Relationships
            'guest_entry' => new GuestEntryResource($this->whenLoaded('guestEntry')),
            'rate' => new RateResource($this->whenLoaded('rate')),
            'guest_type' => new GuestTypeResource($this->whenLoaded('guestType')),
            'auto_discount' => new DiscountResource($this->whenLoaded('autoDiscount')),
            'manual_discount' => new DiscountResource($this->whenLoaded('manualDiscount')),
            
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function getLineDescription(): string
    {
        $description = '';
        
        // Fix: Check if guestType relationship is actually loaded
        if ($this->relationLoaded('guestType') && $this->guestType) {
            $description .= $this->guestType->name;
        } else {
            $description .= 'Guest';
        }
        
        if ($this->guest_count > 1) {
            $description .= " ({$this->guest_count})";
        }
        
        // Fix: Check if rate relationship is actually loaded
        if ($this->relationLoaded('rate') && $this->rate) {
            $description .= " - {$this->rate->rate_name}";
        }
        
        return $description;
    }
}