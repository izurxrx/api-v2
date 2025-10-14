<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_reference' => $this->transaction_reference,
            'transaction_type' => $this->transaction_type,
            'transaction_id' => $this->transaction_id,
            'payment_date' => $this->payment_date?->format('Y-m-d'),
            'payment_time' => $this->payment_time,
            'payment_method' => $this->payment_method,
            'amount_paid' => (float) $this->amount_paid,
            'change_amount' => (float) $this->change_amount,
            'received_by' => $this->received_by,
            'received_by_user' => $this->whenLoaded('receivedBy', function() {
                return [
                    'id' => $this->receivedBy->id,
                    'full_name' => $this->receivedBy->full_name,
                    'username' => $this->receivedBy->username,
                ];
            }),
            'payment_reference' => $this->payment_reference,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}