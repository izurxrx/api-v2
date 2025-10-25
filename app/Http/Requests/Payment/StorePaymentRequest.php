<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_id' => 'required|exists:billings,id',
            'amount_paid' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,gcash,bank_transfer,credit_card,debit_card,other',
            'change_amount' => 'nullable|numeric|min:0',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'billing_id.required' => 'Billing ID is required',
            'billing_id.exists' => 'Billing record not found',
            'amount_paid.required' => 'Payment amount is required',
            'amount_paid.numeric' => 'Payment amount must be a number',
            'amount_paid.min' => 'Payment amount must be greater than zero',
            'payment_method.required' => 'Payment method is required',
            'payment_method.in' => 'Invalid payment method selected',
        ];
    }
}