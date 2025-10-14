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
            'rate_name' => $this->rate_name,
            'rate_category' => $this->rate_category,
            'rate_type' => $this->rate_type,
            'rate_type_label'=> $this->getRateTypeLabel(),
            'base_price' => (float) $this->base_price,
            'formatted_base_price' => number_format($this->base_price, 2),
            'duration' => $this->duration,
            'duration_text' => $this->getDurationText(),
            'extension_fee' => (float) $this->extension_fee,
            'formatted_extension_fee' => number_format($this->extension_fee, 2),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->diffForHumans(),
        ];
    }

    private function getRateTypeLabel(): string
    {
        if (!$this->rate_type) {
            return 'N/A';
        }
        
        return match($this->rate_type) {
            'Day_Based' => 'Day Based',
            'Time_Based' => 'Time Based',
            default => 'N/A',
        };
    }
    
    private function getDurationText(): string
    {
        if (!$this->duration) {
            return 'N/A';
        }
        
        $hours = $this->duration;
        return $hours === 1 ? '1 hour' : "{$hours} hours";
    }
}

