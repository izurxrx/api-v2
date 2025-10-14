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
            'rate' => $this->whenLoaded('rate', function() {
                return [
                    'id' => $this->rate->id,
                    'rate_name' => $this->rate->rate_name,
                    'rate_category' => $this->rate->rate_category,
                    'base_price' => (float) $this->rate->base_price,
                ];
            }),
            'guest_type_id' => $this->guest_type_id,
            'guest_type' => $this->whenLoaded('guestType', function() {
                return [
                    'id' => $this->guestType->id,
                    'name' => $this->guestType->name,
                ];
            }),
            'guest_count' => $this->guest_count,
            'base_rate' => (float) $this->base_rate,
            'auto_discount_id' => $this->auto_discount_id,
            'auto_discount' => $this->whenLoaded('autoDiscount', function() {
                return $this->autoDiscount ? [
                    'id' => $this->autoDiscount->id,
                    'name' => $this->autoDiscount->name,
                    'type' => $this->autoDiscount->type,
                    'value' => (float) $this->autoDiscount->value,
                ] : null;
            }),
            'auto_discount_amount' => (float) $this->auto_discount_amount,
            'manual_discount_id' => $this->manual_discount_id,
            'manual_discount' => $this->whenLoaded('manualDiscount', function() {
                return $this->manualDiscount ? [
                    'id' => $this->manualDiscount->id,
                    'name' => $this->manualDiscount->name,
                    'type' => $this->manualDiscount->type,
                    'value' => (float) $this->manualDiscount->value,
                ] : null;
            }),
            'manual_discount_amount' => (float) $this->manual_discount_amount,
            'final_rate' => (float) $this->final_rate,
            'total_amount' => (float) $this->total_amount,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
