<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'service_name' => $this->service_name,
            'service_provider' => $this->service_provider,
            'amount' => $this->amount,
            'formatted_amount' => '₱' . number_format($this->amount, 2),
            'booking' => new BookingResource($this->whenLoaded('booking')),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->diffForHumans(),
        ];
    }
}