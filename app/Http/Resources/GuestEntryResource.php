<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_reference' => $this->entry_reference,
            'entry_date' => $this->entry_date,
            'entry_time' => $this->entry_time?->format('H:i'),
            'guest_name' => $this->guest_name,
            'contact_number' => $this->contact_number,
            'total_guests' => $this->total_guests,
            'payment_status' => $this->payment_status,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            'amount_paid' => $this->amount_paid,
            'balance' => $this->balance,
            'is_checked_out' => $this->is_checked_out,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            
            // Formatted amounts
            'formatted_subtotal' => '₱' . number_format($this->subtotal, 2, '.', ','),
            'formatted_discount_amount' => $this->discount_amount > 0 
                ? '₱' . number_format($this->discount_amount, 2, '.', ',') 
                : null,
            'formatted_total_amount' => '₱' . number_format($this->total_amount, 2, '.', ','),
            'formatted_amount_paid' => '₱' . number_format($this->amount_paid, 2, '.', ','),
            'formatted_balance' => '₱' . number_format($this->balance, 2, '.', ','),
            
            // Status info
            'payment_status_badge' => $this->getPaymentStatusBadge(),
            'checkout_status' => $this->is_checked_out ? 'Checked Out' : 'Active',
            'checkout_status_badge' => [
                'color' => $this->is_checked_out ? 'secondary' : 'success',
                'text' => $this->is_checked_out ? 'Checked Out' : 'Active'
            ],
            
            // Time calculations
            'is_today' => $this->entry_date->isToday(),
            'entry_age' => $this->created_at?->diffForHumans(),
            
            // Relationships
            'creator' => new UserResource($this->whenLoaded('creator')),
            'details' => GuestEntryDetailResource::collection($this->whenLoaded('details')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->diffForHumans(),
        ];
    }

    private function getPaymentStatusBadge(): array
    {
        $badges = [
            'Unpaid' => ['color' => 'danger', 'text' => 'Unpaid'],
            'Partial' => ['color' => 'warning', 'text' => 'Partial'],
            'Paid' => ['color' => 'success', 'text' => 'Paid'],
        ];

        return $badges[$this->payment_status] ?? ['color' => 'light', 'text' => $this->payment_status];
    }
}