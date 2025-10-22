<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'transaction_reference' => $this->transaction_reference,
            'transaction_type' => $this->transaction_type,
            'transaction_id' => $this->transaction_id,
            
            // Date and time
            'payment_date' => $this->payment_date?->format('Y-m-d'),
            'payment_time' => $this->payment_time ? substr($this->payment_time, 0, 5) : null, // ✅ HH:MM only
            'payment_datetime_formatted' => $this->getFormattedDateTime(),
            
            // Payment details
            'payment_method' => $this->payment_method,
            'amount_paid' => (float) $this->amount_paid,
            'change_amount' => (float) $this->change_amount,
            'payment_reference' => $this->payment_reference,
            'notes' => $this->notes,
            
            // ✅ FIXED: received_by should be just the ID
            'received_by' => $this->getAttributes()['received_by'] ?? $this->getAttribute('received_by'),
            
            // ✅ FIXED: receivedBy should be the user object
            'receivedBy' => $this->receivedBy ? [
                'id' => $this->receivedBy->id,
                'full_name' => $this->receivedBy->full_name,
                'username' => $this->receivedBy->username,
                'contact_no' => $this->receivedBy->contact_no ?? null,
            ] : null,
            
            // Guest name (for display)
            'guest_name' => $this->guest_name ?? null,
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Get formatted date and time (HH:MM only)
     */
    private function getFormattedDateTime(): string
    {
        if (!$this->payment_date) {
            return 'N/A';
        }

        $dateStr = $this->payment_date->format('M d, Y');
        
        if ($this->payment_time) {
            // Extract only hours and minutes
            $time = substr($this->payment_time, 0, 5); // "14:02:10" -> "14:02"
            [$hours, $minutes] = explode(':', $time);
            $hour = (int) $hours;
            $ampm = $hour >= 12 ? 'PM' : 'AM';
            $hour12 = $hour % 12 ?: 12;
            $timeStr = sprintf('%d:%s %s', $hour12, $minutes, $ampm);
            
            return "$dateStr $timeStr";
        }
        
        return $dateStr;
    }
}