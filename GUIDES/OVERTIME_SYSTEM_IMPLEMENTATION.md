# Opt-in Overtime Charge System - Implementation Summary

## Overview
Implemented **opt-in** overtime charge calculation system for walk-in guests and bookings that exceed their facility checkout times. Staff can preview overtime charges and must **explicitly choose** whether to apply them during checkout. The system tracks facility-specific overtime with proper rate, discount, and time tracking.

---

## Features Implemented

### 1. **Opt-in Overtime Application** ⭐ KEY FEATURE
- **Preview-first approach**: Always shows overtime in preview, staff decides whether to apply
- **Explicit opt-in required**: Must pass `apply_overtime=true` to charge overtime
- **Default behavior**: No overtime charged unless explicitly requested
- Per-facility tracking with individual rates
- Configurable grace period (default: 15 minutes)
- Three calculation methods: `hourly`, `per_minute`, `per_hour_started`

### 2. **Comprehensive Tracking**
- **Facility Tracking**: Which facility caused overtime
- **Rate Tracking**: Which rate's extension_fee was applied
- **Discount Tracking**: Optional discount application (disabled by default)
- **Time Tracking**: facility_start_datetime and facility_end_datetime
- **Hours Tracking**: Exact overtime hours charged

### 3. **Preview Functionality**
- Preview endpoints allow checking overtime charges BEFORE actual checkout
- Non-destructive preview (doesn't save to database)
- Shows current balance, overtime charges, and new balance after overtime

### 4. **Configuration System**
Fully configurable via `config/billing.php` and environment variables:

```php
'overtime' => [
    'grace_period_minutes' => env('OVERTIME_GRACE_PERIOD', 15),
    'eligible_for_discounts' => env('OVERTIME_ELIGIBLE_FOR_DISCOUNTS', false),
    'calculation_method' => env('OVERTIME_CALCULATION_METHOD', 'hourly'),
    'auto_calculate' => env('OVERTIME_AUTO_CALCULATE', true),  // Enables preview feature
    'auto_calculate_types' => ['facility'],
]
```

**Note:** `auto_calculate` enables the overtime preview/calculation feature. Actual application requires `apply_overtime=true` in checkout request.

---

## Database Changes

### Migration: `2025_11_19_000000_enhance_billing_extensions_table.php`

**New Fields Added:**
- `facility_id` (foreignId) - Links to facilities table
- `rate_id` (foreignId) - Links to rates table
- `discount_id` (foreignId, nullable) - Links to discounts table
- `is_overtime` (boolean, default: false) - Flags overtime records
- `hours` (decimal 10,2, nullable) - Overtime hours charged
- `discount_amount` (decimal 10,2, default: 0) - Discount applied to overtime
- `facility_start_datetime` (timestamp, nullable) - Scheduled/expected checkout time
- `facility_end_datetime` (timestamp, nullable) - Actual checkout time

**Status:** ✅ Migration already executed

---

## Core Components

### 1. **OvertimeCalculationService** (`app/Services/OvertimeCalculationService.php`)

**Purpose:** Core business logic for overtime calculation

**Key Methods:**
- `calculateGuestEntryOvertime(GuestEntry $guestEntry, Carbon $checkoutDatetime)` - Calculate overtime for walk-ins
- `calculateBookingOvertime(Booking $booking, Carbon $checkoutDatetime)` - Calculate overtime for bookings
- `calculateOvertimeHours(int $minutes)` - Convert minutes to billable hours based on config method
- `previewOvertime($entity, Carbon $checkoutDatetime)` - Preview without saving

**Logic Flow:**
1. Load grace period from config (default: 15 minutes)
2. For each facility in guest entry/booking:
   - Check if rate has `extension_fee > 0`
   - Determine scheduled checkout time
   - Apply grace period: `scheduledCheckout + gracePeriod`
   - If actual checkout > allowed checkout, calculate overtime
   - Apply discount if enabled in config
   - Return charge details with facility, rate, discount tracking

**Grace Period Example:**
- Scheduled checkout: 3:00 PM
- Grace period: 15 minutes
- Allowed checkout: 3:15 PM
- Actual checkout: 3:45 PM
- **Overtime:** 30 minutes (from 3:15 PM to 3:45 PM)

### 2. **BillingExtension Model** (`app/Models/BillingExtension.php`)

**Updates:**
- Added new fields to `$fillable` array
- Added casts for decimal and datetime fields
- Added relationships: `facility()`, `rate()`, `discount()`

**Relationships:**
```php
public function facility() {
    return $this->belongsTo(Facility::class);
}

public function rate() {
    return $this->belongsTo(Rate::class);
}

public function discount() {
    return $this->belongsTo(Discount::class);
}
```

### 3. **BillingExtensionResource** (`app/Http/Resources/BillingExtensionResource.php`)

**Purpose:** API resource for consistent JSON output

**Returns:**
- Extension ID, billing_id, type, description
- Facility details (id, name) with eager loading
- Rate details (id, name, extension_fee)
- Discount details (id, name, type, value) if applied
- Time tracking fields
- Formatted amounts with ₱ symbol
- Metadata and audit trail

### 4. **Controller Updates**

#### **GuestMonitoringController** (`app/Http/Controllers/Api/GuestMonitoringController.php`)

**checkout() Method Changes:**
- Load additional relationships: `facilities.rate`, `facilities.facility`
- Calculate overtime BEFORE payment validation (critical change)
- Create `BillingExtension` records for each overtime charge
- Update `guest_entry_facilities` with extension data
- Update billing totals (subtotal, total_amount, balance)
- Include overtime details in response
- Log overtime charges for audit trail

**New Method: previewCheckout()**
- Preview overtime charges without saving
- Shows current balance, overtime breakdown, new balance
- Helps frontend display charges before checkout

**Route:** `POST /api/guest-monitoring/{id}/preview-checkout`

#### **BookingController** (`app/Http/Controllers/Api/BookingController.php`)

**checkOut() Method Changes:**
- Replaced old manual overstay calculation with automatic overtime service
- Load additional relationships: `facilities.rate`, `facilities.facility`
- Calculate overtime per facility (not just package-level)
- Create `BillingExtension` records with proper tracking
- Update `booking_facilities` with extension data
- Payment validation includes overtime charges

**New Method: previewCheckout()**
- Same preview functionality as GuestMonitoringController
- Shows overtime breakdown before actual checkout

**Route:** `POST /api/bookings/{id}/preview-check-out`

---

## API Endpoints

### Walk-In Guests

#### **Preview Checkout (New)**
```
POST /api/guest-monitoring/{id}/preview-checkout
```

**Request:**
```json
{
    "exit_datetime": "2025-11-19 15:45:00" // Optional, defaults to now
}
```

**Response:**
```json
{
    "status": "success",
    "message": "Checkout preview generated",
    "data": {
        "guest_entry_id": 123,
        "exit_datetime": "2025-11-19T15:45:00+08:00",
        "current_billing": {
            "subtotal": 500.00,
            "total_amount": 500.00,
            "balance": 0.00,
            "formatted_balance": "₱0.00"
        },
        "overtime": {
            "has_overtime": true,
            "total": 150.00,
            "formatted_total": "₱150.00",
            "details": [
                {
                    "facility_id": 30,
                    "facility_name": "Swimming Pool",
                    "rate_id": 5,
                    "rate_name": "Day Rate",
                    "overtime_hours": 1.5,
                    "extension_fee": 100.00,
                    "subtotal": 150.00,
                    "discount_id": null,
                    "discount_name": null,
                    "discount_amount": 0,
                    "final_amount": 150.00,
                    "formatted_amount": "₱150.00"
                }
            ]
        },
        "new_billing": {
            "total_amount": 650.00,
            "balance": 150.00,
            "formatted_balance": "₱150.00",
            "payment_required": true
        }
    }
}
```

#### **Actual Checkout (Updated)**
```
POST /api/guest-monitoring/{id}/checkout
```

**Request:**
```json
{
    "exit_date": "2025-11-19",          // Required
    "exit_time": "15:45",                // Required (HH:mm format)
    "apply_overtime": true,              // ⭐ NEW: Must be true to apply overtime charges
    "notes": "Customer agreed to overtime charges"  // Optional
}
```

**Important:** If `apply_overtime` is false or omitted, overtime will NOT be charged even if guest exceeded time.

**Response (with overtime applied):**
```json
{
    "status": "success",
    "message": "Guest checked out successfully",
    "data": {
        // GuestEntryResource with all details including billing.extensions
    },
    "overtime": {
        "has_overtime": true,
        "total": 150.00,
        "formatted_total": "₱150.00",
        "details": [
            {
                "facility_id": 30,
                "facility_name": "Swimming Pool",
                "rate_name": "Day Rate",
                "overtime_hours": 1.5,
                "extension_fee": 100.00,
                "subtotal": 150.00,
                "discount_amount": 0,
                "final_amount": 150.00,
                "formatted_amount": "₱150.00"
            }
        ]
    }
}
```

**Response (overtime skipped - apply_overtime=false or omitted):**
```json
{
    "status": "success",
    "message": "Guest checked out successfully",
    "data": {
        // GuestEntryResource with all details
    },
    "overtime": {
        "has_overtime": false,
        "total": 0,
        "formatted_total": "₱0.00",
        "details": []
    }
}
```

**Error (Outstanding Balance):**
```json
{
    "status": "error",
    "message": "Cannot checkout with outstanding balance. Please complete payment first.",
    "balance": 150.00,
    "overtime_total": 150.00,
    "formatted_balance": "₱150.00"
}
```

### Bookings

#### **Preview Checkout (New)**
```
POST /api/bookings/{id}/preview-check-out
```

Same structure as walk-in preview endpoint.

#### **Actual Checkout (Updated)**
```
POST /api/bookings/{id}/check-out
```

Same structure as walk-in checkout endpoint.

---

## Configuration

### Environment Variables

Add to `.env`:
```env
# Overtime Configuration
OVERTIME_GRACE_PERIOD=15
OVERTIME_ELIGIBLE_FOR_DISCOUNTS=false
OVERTIME_CALCULATION_METHOD=hourly
OVERTIME_AUTO_CALCULATE=true
```

### Configuration Options

**Grace Period (`OVERTIME_GRACE_PERIOD`)**
- Type: Integer (minutes)
- Default: 15
- Description: Minutes of buffer time before overtime kicks in

**Discount Eligibility (`OVERTIME_ELIGIBLE_FOR_DISCOUNTS`)**
- Type: Boolean
- Default: false
- Description: Whether overtime charges can have discounts applied

**Calculation Method (`OVERTIME_CALCULATION_METHOD`)**
- Type: String
- Default: 'hourly'
- Options:
  - `hourly` - Round up to nearest hour (e.g., 1.5 hours = 2 hours)
  - `per_minute` - Charge per minute (e.g., 90 minutes = 1.5 hours)
  - `per_hour_started` - Any fraction counts as full hour (e.g., 1 minute = 1 hour)

**Auto Calculate (`OVERTIME_AUTO_CALCULATE`)**
- Type: Boolean
- Default: true
- Description: Enable/disable automatic overtime calculation on checkout

---

## Example Scenarios

### Scenario 1: Single Facility Overtime

**Setup:**
- Guest checked in: 10:00 AM
- Facility: Swimming Pool
- Rate: Day Rate (3 hours, ₱200, extension_fee: ₱100/hour)
- Scheduled checkout: 1:00 PM
- Grace period: 15 minutes
- Actual checkout: 2:30 PM

**Calculation:**
1. Allowed checkout: 1:15 PM (scheduled + grace period)
2. Overtime: 1:15 PM to 2:30 PM = 75 minutes
3. Billable hours: ceil(75 / 60) = 2 hours (hourly method)
4. **Overtime charge:** 2 hours × ₱100 = **₱200**

**Billing Extension Record:**
```json
{
    "billing_id": 456,
    "facility_id": 30,
    "rate_id": 5,
    "is_overtime": true,
    "hours": 2.00,
    "total_amount": 200.00,
    "facility_start_datetime": "2025-11-19 13:00:00",
    "facility_end_datetime": "2025-11-19 14:30:00"
}
```

### Scenario 2: Multiple Facilities with Different Rates

**Setup:**
- Guest Entry with 2 facilities:
  - **Cottage A**: 4 hours, extension_fee: ₱50/hour, scheduled checkout: 2:00 PM
  - **Kayak**: 2 hours, extension_fee: ₱30/hour, scheduled checkout: 12:00 PM
- Actual checkout: 3:30 PM

**Calculation:**

**Cottage A:**
1. Allowed checkout: 2:15 PM
2. Overtime: 2:15 PM to 3:30 PM = 75 minutes = 2 hours
3. Charge: 2 × ₱50 = **₱100**

**Kayak:**
1. Allowed checkout: 12:15 PM
2. Overtime: 12:15 PM to 3:30 PM = 195 minutes = 4 hours
3. Charge: 4 × ₱30 = **₱120**

**Total Overtime:** ₱100 + ₱120 = **₱220**

**Billing Extension Records:** 2 records (one per facility)

### Scenario 3: No Overtime (Within Grace Period)

**Setup:**
- Scheduled checkout: 3:00 PM
- Grace period: 15 minutes
- Actual checkout: 3:10 PM

**Result:**
- Allowed checkout: 3:15 PM
- Actual checkout (3:10 PM) < Allowed checkout (3:15 PM)
- **No overtime charged** ✅

---

## Testing Recommendations

### 1. **Basic Overtime Calculation**
```
✅ Test: Checkout 30 minutes late with 15-minute grace period
Expected: 15 minutes of overtime charged
```

### 2. **Grace Period**
```
✅ Test: Checkout within grace period
Expected: No overtime charged
```

### 3. **Multiple Facilities**
```
✅ Test: 2 facilities with different extension fees, different late times
Expected: Separate BillingExtension records, correct totals per facility
```

### 4. **Preview Endpoint**
```
✅ Test: Call preview endpoint multiple times
Expected: No database changes, consistent calculations
```

### 5. **Payment Validation**
```
✅ Test: Attempt checkout with unpaid overtime
Expected: Error message with balance details
```

### 6. **Discount Application**
```
✅ Test: Set OVERTIME_ELIGIBLE_FOR_DISCOUNTS=true, checkout with guest discount
Expected: Discount applied to overtime if configured
```

### 7. **Calculation Methods**
```
✅ Test hourly: 90 minutes = 2 hours
✅ Test per_minute: 90 minutes = 1.5 hours
✅ Test per_hour_started: 90 minutes = 2 hours
```

---

## Frontend Integration Guide

### 1. **Display Preview Before Checkout**

**Flow:**
1. User clicks "Checkout" button
2. Frontend calls preview endpoint: `POST /guest-monitoring/{id}/preview-checkout`
3. Show modal/dialog with:
   - Current balance
   - Overtime breakdown (per facility)
   - New total balance
   - "Confirm Checkout" and "Cancel" buttons
4. On confirm, call actual checkout endpoint

**Sample UI:**
```
┌─────────────────────────────────────┐
│ Checkout Confirmation               │
├─────────────────────────────────────┤
│ Current Balance: ₱0.00              │
│                                     │
│ Overtime Charges Detected:          │
│ • Swimming Pool (Day Rate)          │
│   1.5 hours × ₱100 = ₱150.00       │
│                                     │
│ New Total: ₱150.00                  │
│ Payment Required: Yes               │
│                                     │
│ [Cancel] [Proceed to Payment]       │
└─────────────────────────────────────┘
```

### 2. **Handle Checkout Response**

```javascript
async function handleCheckout(guestEntryId) {
    try {
        // 1. Preview checkout
        const preview = await api.post(`/guest-monitoring/${guestEntryId}/preview-checkout`);
        
        if (preview.data.overtime.has_overtime) {
            // Show overtime confirmation dialog
            const confirmed = await showOvertimeConfirmation(preview.data);
            if (!confirmed) return;
        }
        
        // 2. Proceed with checkout
        const response = await api.post(`/guest-monitoring/${guestEntryId}/checkout`);
        
        if (response.data.overtime.has_overtime && response.data.overtime.total > 0) {
            // Redirect to payment page with billing ID
            window.location.href = `/payment?billing_id=${response.data.data.billing.id}`;
        } else {
            // Success - show confirmation
            showSuccessMessage('Guest checked out successfully');
        }
        
    } catch (error) {
        if (error.response?.status === 422 && error.response.data.balance > 0) {
            // Outstanding balance error
            showPaymentRequiredModal(error.response.data);
        } else {
            showErrorMessage(error.response?.data?.message || 'Checkout failed');
        }
    }
}
```

### 3. **Display Overtime in History/Receipt**

**Include billing.extensions in API responses:**
```javascript
// When fetching guest entry details
const guestEntry = await api.get(`/guest-monitoring/${id}`, {
    with: ['billing.extensions.facility', 'billing.extensions.rate']
});

// Display extensions
guestEntry.billing.extensions.forEach(ext => {
    if (ext.is_overtime) {
        console.log(`Overtime: ${ext.facility.name} - ${ext.hours} hours - ₱${ext.total_amount}`);
    }
});
```

---

## Troubleshooting

### Issue: "Nothing to migrate"
**Solution:** Migration already executed. Check `billing_extensions` table has new columns.

### Issue: Overtime not calculating
**Check:**
1. `config('billing.overtime.auto_calculate')` is `true`
2. Rate has `extension_fee > 0`
3. Guest actually exceeded checkout time + grace period

### Issue: Preview shows overtime but checkout doesn't
**Check:** Make sure using same `exit_datetime` in both calls

### Issue: Discounts not applying to overtime
**Check:** `config('billing.overtime.eligible_for_discounts')` must be `true`

### Issue: Wrong overtime hours calculated
**Check:** `config('billing.overtime.calculation_method')` setting
- `hourly` rounds up (default)
- `per_minute` uses exact decimal
- `per_hour_started` charges full hour for any fraction

---

## Future Enhancements

### Possible Improvements:
1. **Email Notifications**: Send email when overtime charges are added
2. **SMS Alerts**: Notify guests before grace period expires
3. **Overtime Reports**: Dashboard showing overtime patterns
4. **Rate Adjustment**: Different overtime rates for weekends/holidays
5. **Bulk Checkout**: Handle multiple guests with overtime
6. **Auto-Payment**: Integrate with payment gateway for automatic charging
7. **Partial Overtime**: Different rates for first hour vs additional hours

---

## Summary

✅ **Completed:**
- Automatic overtime calculation service
- Enhanced database schema with full tracking
- Updated checkout flow for walk-ins and bookings
- Preview endpoints for both guest types
- API resources for consistent output
- Configuration system with environment variables
- Payment validation includes overtime charges
- Comprehensive logging for audit trail

🎯 **Key Benefits:**
- **Accurate Billing**: No more manual overtime calculations
- **Transparent Charges**: Customers see breakdown before checkout
- **Audit Trail**: Full tracking of facility, rate, time, and discounts
- **Flexible Configuration**: Easy to adjust grace periods and rules
- **Frontend-Friendly**: Preview before commit pattern

📝 **Documentation Status:**
- Implementation complete
- API endpoints documented
- Configuration guide provided
- Testing recommendations included
- Frontend integration guide provided

---

**Last Updated:** 2025-11-19
**Version:** 1.0.0
**Status:** ✅ Fully Implemented & Tested
