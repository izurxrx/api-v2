<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestEntryFacilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'guest_entry_id' => $this->guest_entry_id,
            'facility_id' => $this->facility_id,
            'facility' => $this->whenLoaded('facility', function() {
                return [
                    'id' => $this->facility->id,
                    'name' => $this->facility->name,
                    'facility_type' => [
                        'id' => $this->facility->facilityType->id,
                        'name' => $this->facility->facilityType->name,
                    ],
                ];
            }),
            'rate_id' => $this->rate_id,
            'rate' => $this->whenLoaded('rate', function() {
                return [
                    'id' => $this->rate->id,
                    'rate_name' => $this->rate->rate_name,
                    'base_price' => (float) $this->rate->base_price,
                    'duration' => $this->rate->duration,
                    'extension_fee' => (float) $this->rate->extension_fee,
                ];
            }),
            'start_datetime' => $this->start_datetime?->toIso8601String(),
            'end_datetime' => $this->end_datetime?->toIso8601String(),
            'duration_hours' => (float) $this->duration_hours,
            'base_amount' => (float) $this->base_amount,
            'extension_hours' => (float) $this->extension_hours,
            'extension_amount' => (float) $this->extension_amount,
            'subtotal' => (float) $this->subtotal,
            'is_released' => (bool) $this->is_released,
            'released_at' => $this->released_at?->toIso8601String(),
            'released_by' => $this->released_by,
            'released_by_user' => $this->whenLoaded('releasedBy', function() {
                return [
                    'id' => $this->releasedBy->id,
                    'full_name' => $this->releasedBy->full_name,
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
