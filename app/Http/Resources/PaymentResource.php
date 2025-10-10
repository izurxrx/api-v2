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
            'payment_date' => $this->payment_date,
            'payment_time' => $this->payment_time?->format('H:i'),
            'payment_method' => $this->payment_method,
            'amount_paid' => $this->amount_paid,
            'formatted_amount_paid' => '₱' . number_format($this->amount_paid, 2),
            'received_by' => $this->received_by,
            'notes' => $this->notes,
            
            // Payment method badge
            'payment_method_badge' => $this->getPaymentMethodBadge(),

            // Transaction type badge
            'transaction_type_badge' => $this->getTransactionTypeBadge(),

            // Date/time info
            'payment_datetime' => $this->payment_date->format('M d, Y') . 
                ($this->payment_time ? ' at ' . $this->payment_time->format('g:i A') : ''),
            'is_today' => $this->payment_date->isToday(),
            
            // Relationships
            'receiver' => new UserResource($this->whenLoaded('receiver')),
            'booking' => new BookingResource($this->whenLoaded('booking')),
            'guest_entry' => new GuestEntryResource($this->whenLoaded('guestEntry')),
            
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->diffForHumans(),

        ];
    }

    private function getPaymentMethodBadge(): array
    {
        $method = strtolower(str_replace(['_', ' '], '', $this->payment_method ?? ''));

        $badges = [
            'cash' => ['color' => 'success', 'text' => 'Cash'],
            'gcash' => ['color' => 'primary', 'text' => 'GCash'],
            'card' => ['color' => 'info', 'text' => 'Card'],
            'banktransfer' => ['color' => 'secondary', 'text' => 'Bank Transfer'],
            'check' => ['color' => 'warning', 'text' => 'Check'],
            'online' => ['color' => 'primary', 'text' => 'Online'],
        ];

        $text = ucwords(str_replace('_', ' ', $this->payment_method ?? 'Unknown'));
        return $badges[$method] ?? ['color' => 'light', 'text' => $text];
    }


    private function getTransactionTypeBadge(): array
    {
        $badges = [
            'Booking' => ['color' => 'info', 'text' => 'Booking Payment'],
            'GuestEntry' => ['color' => 'success', 'text' => 'Guest Entry Payment'],
            'Refund' => ['color' => 'danger', 'text' => 'Refund'],
        ];

        return $badges[$this->transaction_type] ?? ['color' => 'light', 'text' => $this->transaction_type];
    }
}