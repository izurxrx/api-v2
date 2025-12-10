# Guest Entry Billing Linkage - Diagnosis & Fix Guide

## 🔍 Issue Report

**Problem:** Frontend cannot find billing for checked-in guest entries

**Reported By:** User

**Date:** November 24, 2025

---

## ✅ Backend Implementation Status

### Check-in Process is Correctly Implemented

**File:** [app/Http/Controllers/Api/GuestMonitoringController.php](app/Http/Controllers/Api/GuestMonitoringController.php:1085-1096)

The check-in process **DOES** link billing to guest entry:

```php
// STEP 6: Link Billing to Guest Entry (Lines 1085-1096)
if ($booking->billing) {
    $booking->billing->update([
        'guest_entry_id' => $guestEntry->id,  // ✅ Billing linked here
    ]);

    Log::info('Billing linked to guest entry', [
        'billing_id' => $booking->billing->id,
        'guest_entry_id' => $guestEntry->id,
        'booking_id' => $booking->id,
    ]);
}
```

### Billing Relationships are Properly Configured

**Billing Model:** [app/Models/Billing.php](app/Models/Billing.php:223-236)
- ✅ Has `guest_entry_id` field (line 65 in fillable)
- ✅ Has `guestEntry()` relationship method

**GuestEntry Model:** [app/Models/GuestEntry.php](app/Models/GuestEntry.php:150-163)
- ✅ Has smart fallback `getBillingAttribute()` method
- ✅ First checks `guest_entry_id` field
- ✅ Falls back to polymorphic relationship

### API Endpoints Load Billing Correctly

**GuestMonitoringController:**
- ✅ `index()` - Line 280: `'billing.payments'`
- ✅ `show()` - Line 342: `'billing.payments.receivedBy'`
- ✅ `checkInBooking()` - Line 1125: Loads billing in response

---

## 🐛 Possible Causes

### Cause 1: Check-in Never Completed (Most Likely)

#### Symptom:
- Booking shows as "Checked_In" in database
- But no corresponding GuestEntry record exists
- Or GuestEntry exists but has no billing

#### Why This Happens:
The check-in process has **strict validation** that can cause silent failures:

**Critical Validation** (Lines 964-978):
```php
if (!$booking->billing->is_downpayment_paid) {
    return response()->json([
        'status' => 'error',
        'message' => sprintf(
            'Downpayment must be paid before check-in. Required: ₱%.2f, Paid: ₱%.2f, Remaining: ₱%.2f',
            $booking->billing->downpayment_amount,
            $booking->billing->downpayment_paid,
            $remainingDownpayment
        ),
    ], 400);
}
```

If this fails, the **entire check-in is aborted**:
- ❌ No GuestEntry created
- ❌ No billing linkage occurs
- ❌ Booking status stays "Confirmed" (not "Checked_In")

#### How to Diagnose:
```sql
-- Find bookings marked as Checked_In without guest entries
SELECT
    b.id,
    b.booking_reference,
    b.booking_status,
    b.actual_check_in_datetime,
    ge.id as guest_entry_id
FROM bookings b
LEFT JOIN guest_entries ge ON ge.booking_id = b.id
WHERE b.booking_status = 'Checked_In'
  AND ge.id IS NULL;
```

---

### Cause 2: Using Deprecated Check-in Endpoint

#### Symptom:
- Check-in appears to succeed
- But GuestEntry is not created properly
- Billing linkage missing

#### Why This Happens:
There are **TWO** check-in endpoints:

**❌ Deprecated (Don't Use):**
```
POST /bookings/{id}/check-in
Controller: BookingController::checkIn()
File: BookingController.php (Lines 617-759)
Status: Marked as DEPRECATED
```

**✅ Correct (Use This):**
```
POST /guest-monitoring/check-in-booking/{bookingId}
Controller: GuestMonitoringController::checkInBooking()
File: GuestMonitoringController.php (Lines 903-1149)
Status: Active and fully functional
```

#### How to Fix:
Update frontend to use correct endpoint:
```javascript
// ❌ Wrong
POST /api/bookings/${bookingId}/check-in

// ✅ Correct
POST /api/guest-monitoring/check-in-booking/${bookingId}
```

---

### Cause 3: Database Migration Not Run

#### Symptom:
- Check-in fails with database error
- Error: "Unknown column 'guest_entry_id' in 'field list'"

#### Why This Happens:
The `guest_entry_id` field was added in a migration that may not have run.

**Migration:** `2025_11_20_095200_add_guest_entry_id_to_billings_table.php`

#### How to Fix:
```bash
php artisan migrate
```

#### How to Verify:
```sql
-- Check if guest_entry_id column exists
DESCRIBE billings;

-- Should show:
-- guest_entry_id | bigint unsigned | YES | NULL
```

---

### Cause 4: Billing Relationship Not Working

#### Symptom:
- GuestEntry exists and has billing linked
- But API response shows `billing: null`

#### Why This Happens:
The relationship method might not be finding the billing due to eager loading issues.

#### How to Diagnose:
```php
// Test in tinker
$guestEntry = GuestEntry::find(1);

// Method 1: Check guest_entry_id link
$billing1 = Billing::where('guest_entry_id', $guestEntry->id)->first();
echo "Via guest_entry_id: " . ($billing1 ? "FOUND" : "NOT FOUND") . "\n";

// Method 2: Check polymorphic link
$billing2 = Billing::where('billable_type', 'App\Models\GuestEntry')
                  ->where('billable_id', $guestEntry->id)
                  ->first();
echo "Via polymorphic: " . ($billing2 ? "FOUND" : "NOT FOUND") . "\n";

// Method 3: Use relationship
$billing3 = $guestEntry->billing;
echo "Via relationship: " . ($billing3 ? "FOUND" : "NOT FOUND") . "\n";
```

---

## 🔧 Diagnostic Queries

### Query 1: Find Guest Entries Without Billing Linkage

```sql
SELECT
    ge.id,
    ge.entry_reference,
    ge.guest_name,
    ge.entry_type,
    ge.booking_id,
    b.id as billing_via_guest_entry_id,
    b2.id as billing_via_polymorphic
FROM guest_entries ge
LEFT JOIN billings b ON b.guest_entry_id = ge.id
LEFT JOIN billings b2 ON b2.billable_type = 'App\\Models\\GuestEntry' AND b2.billable_id = ge.id
WHERE b.id IS NULL AND b2.id IS NULL
  AND ge.is_checked_out = 0
ORDER BY ge.created_at DESC;
```

**Expected Result:** Should return 0 rows

**If returns rows:** These guest entries have no billing linked!

---

### Query 2: Check Booking -> GuestEntry -> Billing Chain

```sql
SELECT
    b.id as booking_id,
    b.booking_reference,
    b.booking_status,
    ge.id as guest_entry_id,
    ge.entry_reference,
    bil.id as billing_id,
    bil.billing_number,
    bil.guest_entry_id as billing_linked_to_ge,
    CASE
        WHEN b.booking_status = 'Checked_In' AND ge.id IS NULL THEN '❌ No GuestEntry created'
        WHEN ge.id IS NOT NULL AND bil.guest_entry_id IS NULL THEN '❌ Billing not linked to GuestEntry'
        WHEN ge.id IS NOT NULL AND bil.guest_entry_id IS NOT NULL THEN '✅ Properly linked'
        ELSE '⚠️ Other issue'
    END as status
FROM bookings b
LEFT JOIN guest_entries ge ON ge.booking_id = b.id
LEFT JOIN billings bil ON bil.billable_id = b.id AND bil.billable_type = 'App\\Models\\Booking'
WHERE b.booking_status = 'Checked_In'
ORDER BY b.actual_check_in_datetime DESC
LIMIT 20;
```

---

### Query 3: Find All Billing Records for a Specific Guest Entry

```sql
-- Replace 123 with actual guest entry ID
SET @guest_entry_id = 123;

SELECT
    bil.id,
    bil.billing_number,
    bil.billable_type,
    bil.billable_id,
    bil.guest_entry_id,
    bil.total_amount,
    bil.amount_paid,
    bil.balance,
    bil.payment_status,
    CASE
        WHEN bil.guest_entry_id = @guest_entry_id THEN '✅ Linked via guest_entry_id'
        WHEN bil.billable_type = 'App\\Models\\GuestEntry' AND bil.billable_id = @guest_entry_id THEN '✅ Linked via polymorphic'
        ELSE '❌ Not linked to this guest entry'
    END as linkage_status
FROM billings bil
WHERE bil.guest_entry_id = @guest_entry_id
   OR (bil.billable_type = 'App\\Models\\GuestEntry' AND bil.billable_id = @guest_entry_id)
   OR (bil.billable_type = 'App\\Models\\Booking' AND bil.billable_id IN (
       SELECT booking_id FROM guest_entries WHERE id = @guest_entry_id
   ));
```

---

### Query 4: Check Recent Check-ins

```sql
SELECT
    b.id as booking_id,
    b.booking_reference,
    b.actual_check_in_datetime,
    ge.id as guest_entry_id,
    ge.entry_reference,
    ge.created_at as ge_created_at,
    bil.id as billing_id,
    bil.guest_entry_id as bil_ge_id,
    TIMESTAMPDIFF(SECOND, b.actual_check_in_datetime, ge.created_at) as seconds_delay,
    CASE
        WHEN bil.guest_entry_id = ge.id THEN '✅ Linked'
        ELSE '❌ NOT linked'
    END as billing_status
FROM bookings b
JOIN guest_entries ge ON ge.booking_id = b.id
LEFT JOIN billings bil ON bil.billable_id = b.id AND bil.billable_type = 'App\\Models\\Booking'
WHERE b.booking_status = 'Checked_In'
  AND b.actual_check_in_datetime >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY b.actual_check_in_datetime DESC;
```

---

## 🩹 Manual Fix for Broken Linkages

If you find guest entries without billing linkage, you can manually fix them:

### Fix Script (Run in Tinker)

```php
// Find all checked-in bookings with guest entries but no billing link
$brokenEntries = DB::table('guest_entries as ge')
    ->join('bookings as b', 'b.id', '=', 'ge.booking_id')
    ->join('billings as bil', function($join) {
        $join->on('bil.billable_id', '=', 'b.id')
             ->where('bil.billable_type', '=', 'App\\Models\\Booking');
    })
    ->whereNull('bil.guest_entry_id')
    ->select('ge.id as guest_entry_id', 'bil.id as billing_id', 'b.booking_reference')
    ->get();

echo "Found " . $brokenEntries->count() . " broken linkages\n";

// Fix each one
foreach ($brokenEntries as $entry) {
    DB::table('billings')
        ->where('id', $entry->billing_id)
        ->update([
            'guest_entry_id' => $entry->guest_entry_id,
            'updated_at' => now(),
        ]);

    echo "✅ Fixed billing #{$entry->billing_id} for guest entry #{$entry->guest_entry_id} (Booking: {$entry->booking_reference})\n";
}

echo "\nAll linkages fixed!\n";
```

---

## 🧪 Testing Procedure

### Test 1: Create Booking and Check In

```bash
# Step 1: Create a new booking with downpayment
POST /api/bookings
{
  "booking_type": "Package",
  "guest_name": "Test Guest",
  "contact_number": "09123456789",
  "number_of_guests": 2,
  "check_in_date": "2025-11-25",
  "check_out_date": "2025-11-27",
  "facilities": [
    {
      "facility_id": 1,
      "rate_id": 10,
      "quantity": 1,
      "rate_amount": 3000
    }
  ],
  "payment": {
    "amount_paid": 1500,  // 50% downpayment
    "payment_method": "cash"
  }
}

# Expected: booking_status = "Confirmed"
# Expected: billing.is_downpayment_paid = true
```

```bash
# Step 2: Check in the booking
POST /api/guest-monitoring/check-in-booking/{booking_id}
{
  "check_in_datetime": "2025-11-25 14:00:00",
  "actual_guests": 2
}

# Expected Response:
{
  "status": "success",
  "message": "Booking checked in successfully",
  "data": {
    "id": 123,  // Guest Entry ID
    "entry_reference": "GE-2025-001",
    "billing": {  // ✅ Should be present!
      "id": 456,
      "billing_number": "BIL-2025-001",
      "total_amount": 3000,
      "amount_paid": 1500,
      "balance": 1500,
      "payment_status": "Partial"
    }
  }
}
```

---

### Test 2: Verify Database Linkage

```sql
-- Get the booking ID from step 1
SET @booking_id = 123;  -- Replace with actual ID

-- Check the complete chain
SELECT
    'Booking' as entity,
    b.id,
    b.booking_reference,
    b.booking_status,
    b.actual_check_in_datetime
FROM bookings b
WHERE b.id = @booking_id

UNION ALL

SELECT
    'GuestEntry' as entity,
    ge.id,
    ge.entry_reference,
    CASE WHEN ge.is_checked_out THEN 'Checked_Out' ELSE 'Active' END,
    ge.check_in_datetime
FROM guest_entries ge
WHERE ge.booking_id = @booking_id

UNION ALL

SELECT
    'Billing' as entity,
    bil.id,
    bil.billing_number,
    bil.payment_status,
    CONCAT('GE_ID: ', COALESCE(bil.guest_entry_id, 'NULL'))
FROM billings bil
WHERE bil.billable_id = @booking_id
  AND bil.billable_type = 'App\\Models\\Booking';
```

**Expected Output:**
```
| entity      | id  | reference       | status       | datetime/ge_id        |
|-------------|-----|-----------------|--------------|----------------------|
| Booking     | 123 | BK-2025-001     | Checked_In   | 2025-11-25 14:00:00  |
| GuestEntry  | 789 | GE-2025-001     | Active       | 2025-11-25 14:00:00  |
| Billing     | 456 | BIL-2025-001    | Partial      | GE_ID: 789           |
```

---

### Test 3: API Response Check

```bash
# Get guest entry details
GET /api/guest-monitoring/entries/{guest_entry_id}

# Verify response contains billing
{
  "status": "success",
  "data": {
    "id": 789,
    "entry_reference": "GE-2025-001",
    "billing": {  // ✅ This must be present!
      "id": 456,
      "billing_number": "BIL-2025-001",
      "total_amount": 3000,
      "amount_paid": 1500,
      "balance": 1500,
      "payment_status": "Partial",
      "payments": [  // ✅ Payments array should also be present
        {
          "id": 1,
          "amount": 1500,
          "payment_method": "cash",
          "payment_date": "2025-11-24T10:00:00Z"
        }
      ]
    }
  }
}
```

---

## 📋 Checklist for Frontend Team

When debugging "billing not found" issues, check:

- [ ] **Correct endpoint used?**
  - ✅ `POST /api/guest-monitoring/check-in-booking/{id}`
  - ❌ NOT `POST /api/bookings/{id}/check-in`

- [ ] **Downpayment paid before check-in?**
  - Check: `booking.billing.is_downpayment_paid === true`

- [ ] **Check-in succeeded (201 status)?**
  - Success: `HTTP 201 Created`
  - Failure: `HTTP 400 Bad Request` with error message

- [ ] **Response includes billing object?**
  - Check: `response.data.billing !== null`
  - Check: `response.data.billing.id` exists

- [ ] **Guest entry ID returned?**
  - Check: `response.data.id` is present
  - Save this for later API calls

- [ ] **Subsequent API calls use guest entry ID?**
  - Use: `/api/guest-monitoring/entries/{guest_entry_id}`
  - NOT: `/api/bookings/{booking_id}`

---

## 🎯 Summary

### Backend Status: ✅ Working Correctly

The check-in process is properly implemented:
- ✅ GuestEntry is created
- ✅ Billing is linked via `guest_entry_id`
- ✅ API endpoints load billing relationship
- ✅ GuestEntry model has smart fallback
- ✅ Database migration is in place

### Most Likely Issues:

1. **Downpayment not paid** → Check-in validation fails silently
2. **Wrong API endpoint** → Using deprecated BookingController::checkIn()
3. **Frontend looking in wrong place** → Checking booking instead of guest entry
4. **Old guest entries** → Created before migration was run

### Immediate Actions:

1. Run diagnostic queries to find broken linkages
2. Use manual fix script if needed
3. Update frontend to use correct endpoint
4. Ensure downpayment validation before attempting check-in
5. Test complete flow from booking → payment → check-in → guest entry with billing

---

**Status:** ✅ Backend implementation is correct
**Date:** November 24, 2025
**Next Step:** Run diagnostic queries to identify specific issue
