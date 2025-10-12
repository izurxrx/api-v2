<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DiscountResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'type' => $this->type,
            'value' => number_format($this->value, 2),
            'is_guest_type_discount' => (bool)$this->is_guest_type_discount,
            'valid_from' => $this->valid_from,
            'valid_until' => $this->valid_until,
        ];
    }
}
