<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Report Settings
    |--------------------------------------------------------------------------
    */

    'company' => [
        'name' => env('COMPANY_NAME', 'DreamSpace'),
        'address' => env('COMPANY_ADDRESS', ' Brgy. Care, Tarlac City 2300 Philippines'),
        'contact' => env('COMPANY_CONTACT', '(+63) 932-358-0889 (Sun) or 910-904-9537 (Smart)'),
        'email' => env('COMPANY_EMAIL', 'info@abcdreamland.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Revenue Recognition
    |--------------------------------------------------------------------------
    */

    'revenue' => [
        // Count revenue based on check-out/completion date
        'recognition_basis' => 'accrual',
        
        // Include forfeited downpayments as revenue
        'include_forfeited_downpayments' => true,
        
        // Completed transaction statuses
        'completed_statuses' => [
            'bookings' => ['Completed', 'Checked_Out'],
            'guest_entries' => ['checked_out'],
        ],
        
        // Payment statuses to include
        'payment_statuses' => ['paid', 'partial'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Date Range Presets
    |--------------------------------------------------------------------------
    */

    'date_presets' => [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this_week' => 'This Week',
        'last_week' => 'Last Week',
        'this_month' => 'This Month',
        'last_month' => 'Last Month',
        'this_quarter' => 'This Quarter',
        'last_quarter' => 'Last Quarter',
        'this_year' => 'This Year',
        'last_year' => 'Last Year',
    ],

    /*
    |--------------------------------------------------------------------------
    | Export Settings
    |--------------------------------------------------------------------------
    */

    'export' => [
        'pdf' => [
            'page_size' => 'short',
            'orientation' => 'portrait',
            'include_charts' => false, // Set to true if you want charts in PDF
        ],
        
        'excel' => [
            'include_charts' => true,
            'multiple_sheets' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    */

    'cache' => [
        'enabled' => true,
        'ttl' => 3600, // 1 hour in seconds
    ],
];