<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rate_name' => $this->rate_name,
            'rate_category' => $this->rate_category,
            'rate_type' => $this->rate_type,
            'rate_type_label' => str_replace('_', ' ', $this->rate_type),
            'base_price' => $this->base_price,
            'formatted_base_price' => '₱' . number_format($this->base_price, 2, '.', ','),
            'duration' => $this->duration,
            'extension_fee' => $this->extension_fee,
            'formatted_extension_fee' => $this->extension_fee 
                ? '₱' . number_format($this->extension_fee, 2, '.', ',') 
                : null,
            'facility_id' => $this->facility_id,
            'facility' => new FacilityResource($this->whenLoaded('facility')),
            'is_time_based' => $this->rate_type === 'Time_Based',
            'has_extension_fee' => !is_null($this->extension_fee) && $this->extension_fee > 0,
            'duration_text' => $this->getDurationText(),
            'display_label' => "{$this->rate_name} - " . ($this->getDurationText() ?? 'N/A') . " ({$this->formatted_base_price})",
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->diffForHumans(),
        ];
    }

    private function getDurationText(): ?string
    {
        if (!$this->duration) {
            return null;
        }

        $hours = $this->duration;
        if ($this->rate_type === 'Day_Based') {
            return $hours >= 24
                ? ($hours / 24) . ' ' . str('Day')->plural($hours / 24)
                : "{$hours} " . str('Hour')->plural($hours);
        }

        if ($this->rate_type === 'Time_Based') {
            return "{$hours} " . str('Hour')->plural($hours);
        }

        return "{$hours} Units";
    }
}