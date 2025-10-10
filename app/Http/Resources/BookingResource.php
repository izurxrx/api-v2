<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_reference' => $this->booking_reference,
            'guest_name' => $this->guest_name,
            'contact_number' => $this->contact_number,
            'check_in_date' => $this->check_in_date,
            'check_out_date' => $this->check_out_date,
            'check_in_time' => $this->check_in_time?->format('H:i'),
            'check_out_time' => $this->check_out_time?->format('H:i'),
            'number_of_guests' => $this->number_of_guests,
            'booking_status' => $this->booking_status,
            'payment_status' => $this->payment_status,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'third_party_service_amount' => $this->third_party_service_amount,
            'total_amount' => $this->total_amount,
            'amount_paid' => $this->amount_paid,
            'balance' => $this->balance,
            'notes' => $this->notes,
            'facility_id' => $this->facility_id,
            'created_by' => $this->created_by,
            
            // Formatted amounts
            'formatted_subtotal' => '₱' . number_format($this->subtotal, 2),
            'formatted_discount_amount' => $this->discount_amount > 0 
                ? '₱' . number_format($this->discount_amount, 2) 
                : null,
            'formatted_total_amount' => '₱' . number_format($this->total_amount, 2),
            'formatted_amount_paid' => '₱' . number_format($this->amount_paid, 2),
            'formatted_balance' => '₱' . number_format($this->balance, 2),
            
            // Status badges
            'booking_status_badge' => $this->getBookingStatusBadge(),
            'payment_status_badge' => $this->getPaymentStatusBadge(),
            
            // Date calculations
            'duration_days' => $this->check_in_date->diffInDays($this->check_out_date) ?: 1,
            'is_overdue' => $this->booking_status === 'Pending' && $this->check_in_date->isPast(),
            'can_check_in' => $this->booking_status === 'Confirmed' && $this->check_in_date->isToday(),
            
            // Relationships
            'facility' => new FacilityResource($this->whenLoaded('facility')),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'services' => BookingServiceResource::collection($this->whenLoaded('services')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function getBookingStatusBadge(): array
    {
        $badges = [
            'Pending' => ['color' => 'warning', 'text' => 'Pending'],
            'Confirmed' => ['color' => 'info', 'text' => 'Confirmed'],
            'Checked_In' => ['color' => 'success', 'text' => 'Checked In'],
            'Checked_Out' => ['color' => 'secondary', 'text' => 'Checked Out'],
            'Cancelled' => ['color' => 'danger', 'text' => 'Cancelled'],
        ];

        return $badges[$this->booking_status] ?? ['color' => 'light', 'text' => $this->booking_status];
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