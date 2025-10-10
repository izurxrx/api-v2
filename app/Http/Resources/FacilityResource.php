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
            'name' => $this->name,
            'quantity' => $this->quantity,
            'expected_capacity' => $this->expected_capacity,
            'max_capacity' => $this->max_capacity,
            'capacity' => "{$this->expected_capacity} / {$this->max_capacity}",
            'description' => $this->description,
            'is_maintenance' => $this->is_maintenance,
            'is_available_for_booking' => $this->is_available_for_booking,
            'is_available' => !$this->is_maintenance && $this->is_available_for_booking,
            'status' => $this->getStatusAttribute(),
            'status_badge' => $this->getStatusBadge(),
            'facility_type_id' => $this->facility_type_id,
            'facility_type' => new FacilityTypeResource($this->whenLoaded('facilityType')),
            'rates' => RateResource::collection($this->whenLoaded('rates')),
            'rates_count' => $this->whenCounted('rates'),
            'active_bookings_count' => $this->when(isset($this->active_bookings_count), $this->active_bookings_count),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->diffForHumans(),
        ];
    }

    private function getStatusAttribute(): string
    {
        if ($this->is_maintenance) return 'Under Maintenance';
        if (!$this->is_available_for_booking) return 'Not Available';
        return 'Available';
    }

    private function getStatusBadge(): array
    {
        return match ($this->getStatusAttribute()) {
            'Available' => ['color' => 'success', 'text' => 'Available'],
            'Not Available' => ['color' => 'secondary', 'text' => 'Not Available'],
            'Under Maintenance' => ['color' => 'warning', 'text' => 'Under Maintenance'],
            default => ['color' => 'light', 'text' => 'Unknown'],
        };
    }
}
