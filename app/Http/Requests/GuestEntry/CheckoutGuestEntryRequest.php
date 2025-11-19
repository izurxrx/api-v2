<?php

namespace App\Http\Requests\GuestEntry;

use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;

class CheckoutGuestEntryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'exit_date' => [
                'required',
                'date',
                'date_format:Y-m-d',
                'before_or_equal:today',  // ✅ Can't be in future
            ],
            'exit_time' => [
                'required',
                'date_format:H:i',
            ],

            // ✅ Overtime application (opt-in)
            'apply_overtime' => 'nullable|boolean',

            // ✅ Notes
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $guestEntryId = $this->route('id');
            $guestEntry = \App\Models\GuestEntry::find($guestEntryId);

            if (!$guestEntry) {
                $validator->errors()->add('id', 'Guest entry not found.');
                return;
            }

            // ✅ Check if already checked out
            if ($guestEntry->is_checked_out) {
                $validator->errors()->add('checkout', 'This guest has already been checked out.');
                return;
            }

            // ✅ Validate exit datetime is after check-in
            try {
                $exitDateTime = Carbon::parse("{$this->exit_date} {$this->exit_time}");
                $checkInDateTime = Carbon::parse($guestEntry->check_in_datetime);
                
                if ($exitDateTime->lt($checkInDateTime)) {
                    $validator->errors()->add(
                        'exit_date',
                        'Exit date/time must be after check-in date/time.'
                    );
                }
                
                // ✅ Warn if checkout is too long ago (e.g., more than 7 days)
                if ($exitDateTime->lt(now()->subDays(7))) {
                    $validator->errors()->add(
                        'exit_date',
                        'Cannot backdate checkout more than 7 days.'
                    );
                }
                
            } catch (\Exception $e) {
                $validator->errors()->add('exit_date', 'Invalid date/time format.');
            }

        });
    }

    public function messages()
    {
        return [
            'exit_date.required' => 'Exit date is required.',
            'exit_date.before_or_equal' => 'Exit date cannot be in the future.',
            'exit_time.required' => 'Exit time is required.',
            'exit_time.date_format' => 'Exit time must be in HH:MM format (e.g., 14:30).',
            'notes.max' => 'Notes cannot exceed 1000 characters.',
        ];
    }
    
    /**
     * ✅ Sanitize input
     */
    protected function prepareForValidation()
    {
        $data = [];
        
        if ($this->has('payment_method')) {
            $data['payment_method'] = trim(strip_tags($this->payment_method));
        }
        
        if ($this->has('notes')) {
            $data['notes'] = trim(strip_tags($this->notes));
        }
        
        $this->merge($data);
    }
}