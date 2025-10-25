<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FacilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'facility_type_id' => $this->facility_type_id,
            'facility_type' => $this->whenLoaded('facilityType', function() {
                return [
                    'id' => $this->facilityType->id,
                    'name' => $this->facilityType->name,
                    'description' => $this->facilityType->description,
                ];
            }),
            'name' => $this->name,
            'quantity' => $this->quantity,
            'expected_capacity' => $this->expected_capacity,
            'max_capacity' => $this->max_capacity,
            'description' => $this->description,
            'requires_entrance' => (bool) $this->requires_entrance,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->diffForHumans(),
        ];
    }
}
