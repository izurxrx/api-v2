<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'guest_entry_id' => $this->guest_entry_id,
            'payment_type' => $this->payment_type,
            'amount' => $this->amount,
            'paid_at' => $this->paid_at?->format('Y-m-d H:i:s'),
            'received_by' => new UserResource($this->receivedBy),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
