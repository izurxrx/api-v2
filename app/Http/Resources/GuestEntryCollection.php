<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class GuestEntryCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'total_entries' => $this->collection->count(),
                'total_guests' => $this->collection->sum('total_guests'),
                'active_entries' => $this->collection->where('is_checked_out', false)->count(),
                'total_amount' => $this->collection->sum('total_amount'),
                'formatted_total_amount' => '₱' . number_format($this->collection->sum('total_amount'), 2, '.', ','),
                'unpaid_count' => $this->collection->where('payment_status', 'Unpaid')->count(),
            ],
        ];
    }
}