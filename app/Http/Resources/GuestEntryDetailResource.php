<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class GuestEntryDetailResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'rate' => [
                'id' => $this->rate?->id,
                'name' => $this->rate?->rate_name,
                'category' => $this->rate?->rate_category,
            ],
            'guest_type' => $this->guestType?->name,
            'guest_count' => $this->guest_count,
            'base_rate' => number_format($this->base_rate, 2),
            'auto_discount' => [
                'id' => $this->autoDiscount?->id,
                'name' => $this->autoDiscount?->name,
                'amount' => number_format($this->auto_discount_amount, 2)
            ],
            'manual_discount' => [
                'id' => $this->manualDiscount?->id,
                'name' => $this->manualDiscount?->name,
                'amount' => number_format($this->manual_discount_amount, 2)
            ],
            'final_rate' => number_format($this->final_rate, 2),
            'total_amount' => number_format($this->total_amount, 2),
        ];
    }
}
