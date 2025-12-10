# Entry Type Filter Implementation - Quick Reference

## What Was Done

Added `entry_type` filter to revenue reports to allow panelists to generate separate reports for bookings vs walk-in entries.

## Files Modified

1. **app/Http/Controllers/Api/ReportController.php**
   - Added `entry_type` validation rule (line 27)
   - Added `entry_type` to filter logic in `revenue()` method (line 55)
   - Added `entry_type` to filter logic in `exportRevenueExcel()` method
   - Added `entry_type` to filter logic in `exportRevenuePdf()` method
   - Updated `filters()` method to return entry_type options (lines 296-299)

2. **app/Services/RevenueReportService.php**
   - Updated `getBookingRevenue()` - Returns 0 if entry_type='walk_in'
   - Updated `getGuestEntryRevenue()` - Returns 0 if entry_type='booking'
   - Updated `getEntranceFees()` - Returns 0 if entry_type='booking'
   - Updated `getFacilityRentals()` - Filters by entry_type
   - Updated `getThirdPartyServices()` - Filters by entry_type
   - Updated `getDiscountsGiven()` - Filters by entry_type
   - Updated `getTransactions()` - Filters transactions by entry_type

## New API Endpoints/Parameters

### Query Parameter: `entry_type`
- **Type**: String (optional)
- **Valid Values**: `booking`, `walk_in`
- **Default**: Omitted (shows all entries)

### Usage Examples

```bash
# Get bookings-only report for this month
curl "https://api.example.com/api/reports/revenue?date_preset=this_month&entry_type=booking"

# Get walk-in-only report for January 1-31
curl "https://api.example.com/api/reports/revenue?date_from=2024-01-01&date_to=2024-01-31&entry_type=walk_in"

# Export bookings-only as Excel
curl "https://api.example.com/api/reports/revenue/export/excel?date_preset=this_month&entry_type=booking"

# Combine with other filters
curl "https://api.example.com/api/reports/revenue?date_preset=this_month&entry_type=booking&facility_type_id=1&payment_method=cash"
```

### Get Available Filters
```bash
curl "https://api.example.com/api/reports/filters"

# Response includes:
{
  "entry_types": [
    {"value": "booking", "label": "Bookings"},
    {"value": "walk_in", "label": "Walk-in Entries"}
  ]
}
```

## Report Output Changes

When `entry_type` filter is applied:

### If `entry_type=booking`:
- `summary.booking_revenue` > 0 (actual booking revenue)
- `summary.guest_entry_revenue` = 0
- `by_source[0].revenue` = total (Bookings = 100%)
- `by_source[1].revenue` = 0 (Walk-ins = 0%)
- `transactions` contains only Booking entries
- `entrance_fees` = 0 (entrance fees only for walk-ins)

### If `entry_type=walk_in`:
- `summary.booking_revenue` = 0
- `summary.guest_entry_revenue` > 0 (actual walk-in revenue)
- `by_source[0].revenue` = 0 (Bookings = 0%)
- `by_source[1].revenue` = total (Walk-ins = 100%)
- `transactions` contains only Walk-in entries
- `entrance_fees` > 0 (included in total)

### If `entry_type` omitted (default):
- Shows combined bookings + walk-in revenue
- All sections populated normally

## Frontend Integration Notes

1. **Add to Filter UI**
   - Include entry_type dropdown alongside existing filters
   - Options: All / Bookings / Walk-in Entries

2. **Update Report Headers**
   - Show filter status: "Showing: Bookings Only" or "Showing: Walk-in Entries Only"

3. **Conditional Display**
   - Entrance fees section: Only relevant for walk-in entries
   - May want to hide or mark N/A when entry_type=booking

4. **Export Filenames**
   - Consider adding filter info to filenames: `revenue_report_bookings_2024-01-01_to_2024-01-31.xlsx`

## Testing

### Basic Tests
```javascript
// Test 1: Get filters
GET /api/reports/filters
// Verify: entry_types array exists with booking and walk_in options

// Test 2: Booking-only report
GET /api/reports/revenue?date_preset=this_month&entry_type=booking
// Verify: booking_revenue > 0, guest_entry_revenue == 0

// Test 3: Walk-in-only report
GET /api/reports/revenue?date_preset=this_month&entry_type=walk_in
// Verify: booking_revenue == 0, guest_entry_revenue > 0

// Test 4: All entries (no filter)
GET /api/reports/revenue?date_preset=this_month
// Verify: Shows combined totals

// Test 5: Invalid entry_type
GET /api/reports/revenue?date_preset=this_month&entry_type=invalid
// Verify: 422 validation error
```

## Validation Rules

```php
'entry_type' => 'nullable|string|in:booking,walk_in'
```

- **nullable**: Can be omitted
- **string**: Must be a string
- **in:booking,walk_in**: Only accepts 'booking' or 'walk_in' values

Invalid values will return 422 Unprocessable Entity response.

## Performance Impact

- **Positive**: When entry_type is specified, filters reduce database queries
  - entry_type=booking: Skips all GuestEntry queries
  - entry_type=walk_in: Skips all Booking queries
  
- **Neutral**: Caching works independently for each filter combination

## Backward Compatibility

✅ Fully backward compatible
- Omitting `entry_type` parameter shows all entries (default behavior)
- Existing integrations continue to work without modification
- Can be added incrementally to frontend

## Database Models

- **Bookings**: `App\Models\Booking`
- **Walk-ins**: `App\Models\GuestEntry`
- **Billing**: `App\Models\Billing` (polymorphic relationship)

Queries differentiate via:
```php
where('billable_type', Booking::class)      // Bookings
where('billable_type', GuestEntry::class)   // Walk-ins
```

## Next Steps

1. **Test in Staging**
   - Verify report accuracy for each filter combination
   - Test export functionality (Excel/PDF)

2. **Frontend Update**
   - Add entry_type filter dropdown
   - Update report display to show filter status
   - Update export filenames as needed

3. **Documentation**
   - Update API documentation
   - Add to panelist reporting guide
   - Document in user manual

