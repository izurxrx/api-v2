<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'transaction_type' => 'required|in:Booking,GuestEntry',
            'transaction_id' => 'required|integer|min:1',
            'payment_date' => 'required|date',
            'payment_time' => 'required|date_format:H:i',
            'payment_method' => 'required|string|max:50',
            'amount_paid' => 'required|numeric|min:0.01',
            'change_amount' => 'nullable|numeric|min:0',
            'payment_reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $transactionType = $this->transaction_type;
            $transactionId = $this->transaction_id;

            if ($transactionType === 'GuestEntry') {
                $exists = \App\Models\GuestEntry::find($transactionId);
                if (!$exists) {
                    $validator->errors()->add('transaction_id', 'Guest entry not found');
                    return;
                }
                
                if ($this->amount_paid > $exists->balance) {
                    $validator->errors()->add('amount_paid', 
                        'Payment amount exceeds balance of ₱' . number_format($exists->balance, 2)
                    );
                }
            } elseif ($transactionType === 'Booking') {
                $exists = \App\Models\Booking::find($transactionId);
                if (!$exists) {
                    $validator->errors()->add('transaction_id', 'Booking not found');
                    return;
                }
                
                if ($this->amount_paid > $exists->balance) {
                    $validator->errors()->add('amount_paid', 
                        'Payment amount exceeds balance of ₱' . number_format($exists->balance, 2)
                    );
                }
            }

            $onlineMethods = ['GCash', 'Maya', 'Bank Transfer', 'Credit Card', 'Debit Card'];
            if (in_array($this->payment_method, $onlineMethods)) {
                if (empty($this->payment_reference)) {
                    $validator->errors()->add('payment_reference', 
                        'Reference number required for ' . $this->payment_method
                    );
                }
            }
        });
    }

    public function messages()
    {
        return [
            'transaction_type.in' => 'Transaction type must be Booking or GuestEntry',
            'payment_time.date_format' => 'Time must be HH:MM format (e.g., 14:30)',
            'payment_method.in' => 'Invalid payment method',
            'amount_paid.min' => 'Amount must be greater than ₱0.00',
        ];
    }
}