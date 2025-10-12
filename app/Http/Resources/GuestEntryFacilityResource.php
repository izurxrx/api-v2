<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class GuestEntryFacilityResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'facility' => [
                'id' => $this->facility?->id,
                'name' => $this->facility?->name,
            ],
            'rate' => [
                'id' => $this->rate?->id,
                'name' => $this->rate?->rate_name,
            ],
            'start_datetime' => $this->start_datetime,
            'end_datetime' => $this->end_datetime,
            'duration_hours' => $this->duration_hours,
            'base_amount' => number_format($this->base_amount, 2),
            'extension_hours' => $this->extension_hours,
            'extension_amount' => number_format($this->extension_amount, 2),
            'subtotal' => number_format($this->subtotal, 2),
        ];
    }
}
