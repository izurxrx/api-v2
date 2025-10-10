<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'type' => str_replace('_', ' ', $this->type),
            'value' => $this->value,
            'is_guest_type_discount' => $this->is_guest_type_discount,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'formatted_value' => $this->formatValue(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->diffForHumans(),
        ];
    }

    private function formatValue(){
        if ($this->type === 'Percentage') {
        // Remove trailing zeros after the decimal point
            return rtrim(rtrim(number_format($this->value, 2, '.', ''), '0'), '.') . '%';
        }

        // Fixed amount
        return '₱' . number_format($this->value, 2);
    }
}