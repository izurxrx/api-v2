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
            'guest_type_name' => $this->guest_type_name,
            'rate_id' => $this->rate_id,
            'rate' => $this->whenLoaded('rate', function() {
                return [
                    'id' => $this->rate->id,
                    'rate_name' => $this->rate->rate_name,
                    'rate_category' => $this->rate->rate_category,
                    'base_price' => (float) $this->rate->base_price,
                ];
            }),
            'guest_count' => $this->guest_count,
            'base_rate' => (float) $this->base_rate,
            'discount_mode' => $this->discount_mode,
            'discount_id' => $this->discount_id,
            'discount' => $this->whenLoaded('discount', function() {  // ✅ Change from autoDiscount
                return $this->discount ? [
                    'id' => $this->discount->id,
                    'name' => $this->discount->name,
                    'type' => $this->discount->type,
                    'value' => (float) $this->discount->value,
                ] : null;
            }),
            'discount_amount' => (float) $this->discount_amount,
            'final_rate' => (float) $this->final_rate,
            'total_amount' => (float) $this->total_amount,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->diffForHumans(),
        ];
    }
}