# Entry Type Filter Implementation Guide

## Overview
Added `entry_type` filter to revenue reports, allowing panelists to generate separate reports for bookings vs walk-in entries.

## Changes Made

### 1. ReportController (`app/Http/Controllers/Api/ReportController.php`)

#### Added Validation
Added `entry_type` validation rule to all three report methods:
- `revenue()`
- `exportRevenueExcel()`
- `exportRevenuePdf()`

```php
'entry_type' => 'nullable|string|in:booking,walk_in',
```

#### Added Filter Logic
In each method, added entry_type to the filter array:
```php
if (!empty($validated['entry_type'])) {
    $filters['entry_type'] = $validated['entry_type'];
}
```

#### Updated filters() Method
Added `entry_types` array to response:
```php
'entry_types' => [
    ['value' => 'booking', 'label' => 'Bookings'],
    ['value' => 'walk_in', 'label' => 'Walk-in Entries'],
],
```

### 2. RevenueReportService (`app/Services/RevenueReportService.php`)

#### Updated Revenue Calculation Methods

**getBookingRevenue()** & **getGuestEntryRevenue()**
- Returns 0 when opposite entry_type is filtered
- Example: getBookingRevenue() returns 0 if entry_type='walk_in'

**getEntranceFees()**
- Returns 0 if entry_type='booking' (entrance fees only for walk-ins)

**getFacilityRentals()**
- Conditionally includes booking and walk-in facility revenues based on entry_type filter

**getThirdPartyServices()**
- Conditionally includes booking and walk-in service amounts based on entry_type filter

**getDiscountsGiven()**
- Conditionally includes booking and walk-in discounts based on entry_type filter

**getTransactions()**
- Queries only bookings if entry_type='booking'
- Queries only guest_entries if entry_type='walk_in'
- Queries both if entry_type is omitted

## API Usage

### Get Available Filters
```http
GET /api/reports/filters

Response:
{
  "status": "success",
  "data": {
    "facility_types": [...],
    "payment_methods": [...],
    "entry_types": [
      {"value": "booking", "label": "Bookings"},
      {"value": "walk_in", "label": "Walk-in Entries"}
    ],
    "staff": [...]
  }
}
```

### Generate Booking-Only Revenue Report
```http
GET /api/reports/revenue?date_preset=this_month&entry_type=booking

Response:
{
  "status": "success",
  "data": {
    "summary": {
      "total_revenue": 50000,
      "booking_revenue": 50000,
      "booking_percentage": 100,
      "guest_entry_revenue": 0,
      "guest_entry_percentage": 0,
      ...
    },
    "breakdown": {...},
    "by_facility": [...],
    "by_payment_method": [...],
    "by_source": [
      {
        "source": "Bookings",
        "revenue": 50000,
        "percentage": 100
      },
      {
        "source": "Walk-ins",
        "revenue": 0,
        "percentage": 0
      }
    ],
    "transactions": [
      {
        "transaction_type": "Booking",
        ...
      }
    ]
  }
}
```

### Generate Walk-in-Only Revenue Report
```http
GET /api/reports/revenue?date_preset=this_month&entry_type=walk_in

Response:
{
  "status": "success",
  "data": {
    "summary": {
      "total_revenue": 30000,
      "booking_revenue": 0,
      "booking_percentage": 0,
      "guest_entry_revenue": 30000,
      "guest_entry_percentage": 100,
      ...
    },
    "breakdown": {...},
    "transactions": [
      {
        "transaction_type": "Walk-in",
        ...
      }
    ]
  }
}
```

### Export Filtered Reports
```http
GET /api/reports/revenue/export/excel?date_preset=this_month&entry_type=booking
GET /api/reports/revenue/export/pdf?date_preset=this_month&entry_type=walk_in
```

### Combined Filtering
Entry type filter works seamlessly with existing filters:

```http
GET /api/reports/revenue?date_preset=this_month&entry_type=booking&facility_type_id=1&payment_method=cash

# Results in report showing:
# - Only bookings (entry_type=booking)
# - Only from facility_type_id=1
# - Only cash payments (payment_method=cash)
```

## Implementation Details

### Filter Logic Pattern
All filtering methods follow this pattern:

```php
// Skip if opposite entry_type is filtered
if (!empty($this->filters['entry_type']) && $this->filters['entry_type'] === 'opposite_type') {
    return 0;
}

// Or for mixed sources:
if (empty($this->filters['entry_type']) || $this->filters['entry_type'] === 'booking') {
    // Include booking data
}
if (empty($this->filters['entry_type']) || $this->filters['entry_type'] === 'walk_in') {
    // Include walk-in data
}
```

### Backend Models
- **Bookings**: `App\Models\Booking` (booking-related revenue)
- **Walk-ins**: `App\Models\GuestEntry` (walk-in entry revenue)
- **Billing**: Polymorphic relationship with billable_type field

### Billable Types
- `App\Models\Booking::class` - Booking entries
- `App\Models\GuestEntry::class` - Walk-in entries

## Frontend Integration

### 1. Filter Dropdown Component
```javascript
// Add entry_type filter to report filter form
const entryTypeOptions = [
  { value: 'booking', label: 'Bookings' },
  { value: 'walk_in', label: 'Walk-in Entries' }
];

// Include in filter UI alongside facility_type_id, payment_method, etc.
```

### 2. Report Request
```javascript
const params = {
  date_preset: selectedDatePreset,
  entry_type: selectedEntryType, // Optional
  facility_type_id: selectedFacility,
  payment_method: selectedPayment,
  staff_id: selectedStaff
};

const response = await fetch(`/api/reports/revenue?${new URLSearchParams(params)}`);
```

### 3. Display Considerations
- Show "Report Type: Bookings" or "Report Type: Walk-in Entries" when filtered
- Highlight that certain sections (e.g., entrance fees) only apply to walk-ins
- Display "N/A" or hide sections that have 0 values when filtered

## Testing Checklist

- [ ] GET /api/reports/filters includes entry_types
- [ ] entry_type=booking returns only booking revenue
- [ ] entry_type=walk_in returns only walk-in revenue
- [ ] entry_type omitted returns combined revenue
- [ ] Excel export with entry_type=booking works
- [ ] PDF export with entry_type=walk_in works
- [ ] Combined filters (entry_type + facility_type_id) work
- [ ] Combined filters (entry_type + payment_method) work
- [ ] Invalid entry_type value returns 422 validation error
- [ ] Cache keys properly distinguish between filtered reports
- [ ] All breakdown sections (by_facility, by_payment_method) respect filter

## Cache Behavior

Cache keys include filters in their hash:
```
report:revenue_summary:2024-12-01:2024-12-31:abc123def456 (combined)
report:revenue_summary:2024-12-01:2024-12-31:789ghi012jkl (bookings only)
report:revenue_summary:2024-12-01:2024-12-31:345mno678pqr (walk-ins only)
```

Different entry_type filters generate different cache keys, so reports are cached independently.

## Performance Notes

- Entry type filtering reduces queries by conditionally skipping Booking or GuestEntry queries
- When entry_type='booking': skips all GuestEntry queries
- When entry_type='walk_in': skips all Booking queries
- When entry_type omitted: executes all queries (normal behavior)

## Future Enhancements

1. **Report Comparisons**: Compare booking vs walk-in trends side-by-side
2. **Revenue Attribution**: Track which features drive booking vs walk-in revenue
3. **Scheduling**: Auto-generate separate reports for each entry type
4. **Analytics**: Revenue per entry type over time (trending)

