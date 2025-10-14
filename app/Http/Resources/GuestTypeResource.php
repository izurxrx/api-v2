<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'default_discount_id' => $this->default_discount_id,
            'default_discount' => $this->whenLoaded('defaultDiscount', function() {
                return $this->defaultDiscount ? [
                    'id' => $this->defaultDiscount->id,
                    'name' => $this->defaultDiscount->name,
                    'type' => $this->defaultDiscount->type,
                    'value' => (float) $this->defaultDiscount->value,
                ] : null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}