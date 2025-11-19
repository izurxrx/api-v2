<?php

return [
    
    /*
    |--------------------------------------------------------------------------
    | Booking Configuration
    |--------------------------------------------------------------------------
    |
    | Settings related to booking reservations
    |
    */
    
    'booking' => [
        
        /**
         * Downpayment percentage required to confirm booking
         * Default: 50% (0.5)
         */
        'downpayment_percentage' => env('BOOKING_DOWNPAYMENT_PERCENT', 50),
        
        /**
         * Hours before automatic cancellation if no downpayment received
         * Default: 24 hours
         */
        'payment_deadline_hours' => env('BOOKING_PAYMENT_DEADLINE', 24),
        
        /**
         * Hours before check-in that free cancellation is allowed
         * Default: 72 hours (3 days)
         */
        'cancellation_hours_before_checkin' => env('BOOKING_CANCELLATION_HOURS', 72),
        
        /**
         * Grace period for late check-in (hours)
         * Default: 2 hours
         */
        'checkin_grace_period_hours' => env('BOOKING_CHECKIN_GRACE', 2),
        
        /**
         * Early check-in allowed (hours before scheduled time)
         * Default: 1 hour
         */
        'early_checkin_hours' => env('BOOKING_EARLY_CHECKIN', 1),
        
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Guest Entry Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for walk-in guest entries
    |
    */
    
    'guest_entry' => [
        
        /**
         * Require full payment before check-in
         */
        'requires_full_payment' => true,
        
        /**
         * Automatically checkout guests with free entry (children below 2)
         */
        'auto_checkout_if_free' => true,
        
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Payment Configuration
    |--------------------------------------------------------------------------
    |
    | Settings related to payment processing
    |
    */
    
    'payment' => [
        
        /**
         * Time window (in hours) within which payment can be reversed
         * Default: 24 hours
         */
        'reversal_window_hours' => env('PAYMENT_REVERSAL_HOURS', 24),
        
        /**
         * Available payment methods
         */
        'methods' => [
            'cash' => 'Cash',
            'gcash' => 'GCash',
            'bank_transfer' => 'Bank Transfer',
            'credit_card' => 'Credit Card',
            'debit_card' => 'Debit Card',
            'other' => 'Other',
        ],
        
        /**
         * Payment types
         */
        'types' => [
            'full' => 'Full Payment',
            'downpayment' => 'Downpayment',
            'partial' => 'Partial Payment',
            'balance' => 'Balance Payment',
            'reversal' => 'Payment Reversal',
        ],
        
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Status Configuration
    |--------------------------------------------------------------------------
    |
    | Valid statuses for billing and payment
    |
    */
    
    'status' => [
        
        'billing' => [
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'active' => 'Active',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'voided' => 'Voided',
        ],
        
        'payment' => [
            'unpaid' => 'Unpaid',
            'partial' => 'Partial',
            'paid' => 'Paid',
            'refunded' => 'Refunded',
            'cancelled' => 'Cancelled',
        ],
        
        'booking' => [
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'checked_in' => 'Checked_In',
            'checked_out' => 'Checked_Out',
            'cancelled' => 'Cancelled',
            'no_show' => 'No_Show',
        ],
        
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Notification Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for automated notifications
    |
    */
    
    'notifications' => [
        
        /**
         * Send reminder before payment deadline
         */
        'payment_reminder_hours' => [12, 6, 1], // 12h, 6h, 1h before deadline
        
        /**
         * Send overdue payment notifications
         */
        'overdue_notification_enabled' => env('NOTIFY_OVERDUE_PAYMENTS', true),
        
        /**
         * Send booking confirmation email
         */
        'booking_confirmation_enabled' => env('SEND_BOOKING_CONFIRMATION', true),
        
        /**
         * Send payment receipt
         */
        'payment_receipt_enabled' => env('SEND_PAYMENT_RECEIPT', true),
        
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Automation Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for automated tasks
    |
    */
    
    'automation' => [
        
        /**
         * Auto-cancel unpaid bookings
         */
        'auto_cancel_unpaid' => env('AUTO_CANCEL_UNPAID_BOOKINGS', true),
        
        /**
         * Mark as no-show if not checked in after grace period
         */
        'auto_mark_no_show' => env('AUTO_MARK_NO_SHOW', true),
        
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Overtime Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for automatic overtime charge calculation
    |
    */
    
    'overtime' => [
        
        /**
         * Grace period before overtime kicks in (minutes)
         * Default: 15 minutes
         */
        'grace_period_minutes' => env('OVERTIME_GRACE_PERIOD', 15),
        
        /**
         * Allow discounts to be applied to overtime charges
         * Default: false (standard industry practice)
         */
        'eligible_for_discounts' => env('OVERTIME_ELIGIBLE_FOR_DISCOUNTS', false),
        
        /**
         * Overtime calculation method
         * Options: 'hourly', 'per_minute', 'per_hour_started'
         * Default: 'hourly' (round up to nearest hour)
         */
        'calculation_method' => env('OVERTIME_CALCULATION_METHOD', 'hourly'),
        
        /**
         * Enable overtime calculation feature
         * When true, overtime can be calculated via preview endpoint
         * Staff must explicitly apply overtime during checkout by passing apply_overtime=true
         * Default: true
         */
        'auto_calculate' => env('OVERTIME_AUTO_CALCULATE', true),
        
        /**
         * Extension types that should auto-calculate overtime
         */
        'auto_calculate_types' => ['facility'],
        
    ],
    
];