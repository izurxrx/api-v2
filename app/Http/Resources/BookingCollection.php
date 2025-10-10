<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class BookingCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'total_bookings' => $this->collection->count(),
                'pending_count' => $this->collection->where('booking_status', 'Pending')->count(),
                'confirmed_count' => $this->collection->where('booking_status', 'Confirmed')->count(),
                'total_amount' => $this->collection->sum('total_amount'),
                'formatted_total_amount' => '₱' . number_format($this->collection->sum('total_amount'), 2),
            ],
        ];
    }
}