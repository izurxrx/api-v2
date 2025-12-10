# Facility Availability & Auto-Cancellation System

## ✅ Overview

This system implements a two-part solution for managing facility availability and booking conflicts:

1. **Pending bookings DO NOT reduce facility availability** - Only confirmed/checked-in bookings reserve facilities
2. **Auto-cancellation of conflicting pending bookings** - When a booking is confirmed, conflicting pending bookings are automatically cancelled

---

## 🎯 Business Logic

### Part 1: Availability Rules

**Which booking statuses block facility availability?**

| Status | Blocks Availability? | Reason |
|--------|---------------------|---------|
| **Pending** | ❌ No | Guest hasn't paid downpayment yet, booking not secured |
| **Confirmed** | ✅ Yes | Downpayment paid (50%+), facility is reserved |
| **Checked_In** | ✅ Yes | Guest is actively using the facility |
| **Checked_Out** | ❌ No | Guest has left, facility is now available |
| **Cancelled** | ❌ No | Booking was cancelled, facility is available |
| **No_Show** | ❌ No | Guest never arrived, facility is available |

### Part 2: Auto-Cancellation Rules

**When does auto-cancellation trigger?**

1. A booking receives downpayment (50%+) or full payment
2. Booking status changes from 'Pending' to 'Confirmed'
3. System checks for other pending bookings with overlapping times
4. Pending bookings that would now exceed facility capacity are automatically cancelled

**What gets cancelled?**

- Only 'Pending' status bookings
- Only those that share the same facilities
- Only those with overlapping dates/times
- Only those that would exceed available capacity

---

## 🔄 How It Works

### Scenario 1: Multiple Pending Bookings (No Conflict)

```
Facility: Cottage #1 (Quantity: 1)

Timeline:
1. Guest A creates booking (Nov 24-26) → Status: Pending
   → Facility still available ✅

2. Guest B creates booking (Nov 24-26) → Status: Pending
   → Facility still available ✅

3. Guest C creates booking (Nov 24-26) → Status: Pending
   → Facility still available ✅

Result: All 3 pending bookings exist simultaneously
```

### Scenario 2: First Guest Confirms (Triggers Auto-Cancellation)

```
Continuing from Scenario 1...

4. Guest A pays downpayment → Status: Confirmed
   → Facility NOW reserved by Guest A ✅
   → System checks: Guest B and C's bookings would exceed capacity
   → Auto-cancels Guest B's booking ❌
   → Auto-cancels Guest C's booking ❌

Final State:
- Guest A: Confirmed ✅
- Guest B: Cancelled (auto) ❌
- Guest C: Cancelled (auto) ❌
```

### Scenario 3: Different Quantities

```
Facility: Jetski (Quantity: 3)

Timeline:
1. Guest A books 2 jetskis (Nov 24) → Pending
   → Available: 3 ✅

2. Guest B books 1 jetski (Nov 24) → Pending
   → Available: 3 ✅

3. Guest C books 2 jetskis (Nov 24) → Pending
   → Available: 3 ✅

4. Guest A pays → Confirmed (2 jetskis reserved)
   → Available: 1 (3 total - 2 reserved)
   → Guest B booking OK (1 ≤ 1) ✅
   → Guest C booking exceeds capacity (2 > 1) ❌
   → Auto-cancels Guest C's booking

Final State:
- Guest A: Confirmed (2 jetskis) ✅
- Guest B: Pending (1 jetski) ✅
- Guest C: Cancelled (auto) ❌
```

### Scenario 4: Partial Overlap

```
Facility: Cottage #1 (Quantity: 1)

Timeline:
1. Guest A books Nov 24-26 → Pending
2. Guest B books Nov 25-27 → Pending (overlaps with A)
3. Guest C books Nov 26-28 → Pending (overlaps with A & B)

4. Guest A pays → Confirmed
   → Guest B booking overlaps (Nov 25-26) ❌ Auto-cancelled
   → Guest C booking overlaps (Nov 26) ❌ Auto-cancelled

5. After cancellations, facility available Nov 26-28
6. Guest D can now book Nov 27-28 ✅
```

---

## 📋 Implementation Details

### 1. FacilityAvailabilityService

**File:** `app/Services/FacilityAvailabilityService.php`

**Changes Made:**
- Line 76: Changed from `['Pending', 'Confirmed', 'Checked_In']` to `['Confirmed', 'Checked_In']`
- Line 211: Same change for conflict detection

**Code:**
```php
// ✅ Count occupied by Bookings - ONLY Confirmed and Checked_In bookings reduce availability
// Pending bookings do NOT reserve facilities until confirmed
$occupiedByBookings = BookingFacility::where('facility_id', $facilityId)
    ->whereHas('booking', function($q) use ($start, $end, $excludeBookingId) {
        $q->where(function($query) use ($start, $end) {
            $query->where('check_in_datetime', '<', $end)
                  ->where('check_out_datetime', '>', $start);
        })
        ->whereIn('booking_status', ['Confirmed', 'Checked_In']);

        if ($excludeBookingId) {
            $q->where('id', '!=', $excludeBookingId);
        }
    })
    ->sum('quantity');
```

---

### 2. BookingConflictResolutionService

**File:** `app/Services/BookingConflictResolutionService.php` (New)

**Purpose:** Handles automatic cancellation of conflicting pending bookings

**Main Methods:**

#### `cancelConflictingPendingBookings(Booking $confirmedBooking)`
- Called when a booking is confirmed
- Finds pending bookings with overlapping times and facilities
- Checks if they would exceed capacity
- Cancels those that would exceed

**Flow:**
```php
1. Check confirmed booking has facilities
2. For each facility in confirmed booking:
   a. Find pending bookings with same facility
   b. Filter by overlapping dates
   c. Check if pending quantity > available quantity
   d. If yes, cancel the pending booking
3. Log all cancellations
4. Return summary
```

#### `getConflictingPendingBookings(Booking $booking)`
- Preview which pending bookings would be cancelled
- Useful for showing warnings before confirming

---

### 3. BillingService Integration

**File:** `app/Services/BillingService.php`

**Changes Made:**
- Added `BookingConflictResolutionService` to constructor
- Added `cancelConflictingPendingBookings()` helper method
- Integrated auto-cancellation when booking is confirmed (lines 76, 86)

**Code:**
```php
if ($amountPaid >= $totalAmount) {
    // Full payment
    $this->recordPayment($billing, $paymentData, 'full');

    // Update booking to Confirmed
    $booking->update(['booking_status' => 'Confirmed']);

    // ✅ Auto-cancel conflicting pending bookings
    $this->cancelConflictingPendingBookings($booking);
}
```

---

## 📝 Cancellation Details

### What Happens to Cancelled Bookings?

**Booking Updates:**
```php
$booking->update([
    'booking_status' => 'Cancelled',
    'cancellation_reason' => "Auto-cancelled: Facility 'Cottage #1' confirmed by another booking (BK-2025-001). Requested quantity (1) exceeds available quantity (0).",
    'notes' => $booking->notes . ' | ' . $cancellationReason,
]);
```

**Billing Updates:**
```php
$billing->update([
    'payment_status' => 'cancelled',
]);
```

### Cancellation Reason Format

```
Auto-cancelled: Facility 'Cottage #1' confirmed by another booking (BK-2025-001).
Requested quantity (1) exceeds available quantity (0).
```

Includes:
- Facility name
- Confirmed booking reference that triggered cancellation
- Requested quantity
- Available quantity

---

## 📊 Logging & Audit Trail

### Conflict Resolution Logs

**When checking for conflicts:**
```
🔍 Checking for conflicting pending bookings
- confirmed_booking_id: 123
- confirmed_booking_reference: BK-2025-001
- facility_id: 5
- facility_name: Cottage #1
- check_in: 2025-11-24 14:00:00
- check_out: 2025-11-26 12:00:00
```

**Found pending bookings:**
```
📋 Found 2 pending bookings with overlapping time
- facility_id: 5
```

**Availability check:**
```
🔢 Availability check for pending booking
- pending_booking_id: 124
- pending_booking_reference: BK-2025-002
- requested_quantity: 1
- available_quantity: 0
- would_exceed: true
```

**Cancellation:**
```
❌ Auto-cancelled pending booking due to conflict
- cancelled_booking_id: 124
- cancelled_booking_reference: BK-2025-002
- confirmed_by_booking_id: 123
- confirmed_by_booking_reference: BK-2025-001
- facility: Cottage #1
- reason: Auto-cancelled: Facility 'Cottage #1' confirmed by another booking...
```

**Summary:**
```
✅ Conflict resolution completed
- confirmed_booking_id: 123
- confirmed_booking_reference: BK-2025-001
- cancelled_count: 2
```

---

## 🧪 Testing Scenarios

### Test 1: Create Multiple Pending Bookings

```bash
POST /api/bookings
Authorization: Bearer {token}
Content-Type: application/json

{
  "booking_type": "Package",
  "guest_name": "Guest A",
  "contact_number": "09123456789",
  "number_of_guests": 4,
  "check_in_date": "2025-11-24",
  "check_out_date": "2025-11-26",
  "facilities": [
    {
      "facility_id": 1,
      "rate_id": 10,
      "quantity": 1,
      "rate_amount": 3000
    }
  ],
  "payment": {
    "amount_paid": 0
  }
}
```

**Expected Result:**
- Booking created with status 'Pending'
- Facility availability still shows 1 available
- Can create another pending booking for same facility/dates

---

### Test 2: Confirm First Booking (Triggers Auto-Cancellation)

```bash
POST /api/billings/{billing_id}/payments
Authorization: Bearer {token}
Content-Type: application/json

{
  "amount": 1500,
  "payment_method": "cash"
}
```

**Expected Result:**
- Guest A's booking status → 'Confirmed'
- Facility availability → 0 (now reserved)
- Other pending bookings for same facility/dates → Auto-cancelled
- Response includes cancellation summary

**Check logs:**
```bash
storage/logs/laravel.log
```

Look for:
- "Conflict resolution completed"
- "Auto-cancelled pending booking due to conflict"

---

### Test 3: Check Cancelled Booking Details

```bash
GET /api/bookings/{cancelled_booking_id}
Authorization: Bearer {token}
```

**Expected Response:**
```json
{
  "status": "success",
  "data": {
    "id": 124,
    "booking_reference": "BK-2025-002",
    "booking_status": "Cancelled",
    "cancellation_reason": "Auto-cancelled: Facility 'Cottage #1' confirmed by another booking (BK-2025-001). Requested quantity (1) exceeds available quantity (0).",
    "notes": "Guest requested early check-in | Auto-cancelled: Facility 'Cottage #1' confirmed...",
    "billing": {
      "payment_status": "cancelled"
    }
  }
}
```

---

### Test 4: Verify Facility Availability

```bash
GET /api/facilities/1/availability?start_datetime=2025-11-24T14:00:00&end_datetime=2025-11-26T12:00:00&quantity=1
Authorization: Bearer {token}
```

**Expected Response (Before Confirmation):**
```json
{
  "available": true,
  "available_quantity": 1,
  "requested_quantity": 1,
  "facility": {
    "id": 1,
    "name": "Cottage #1",
    "total_quantity": 1
  }
}
```

**Expected Response (After Confirmation):**
```json
{
  "available": false,
  "available_quantity": 0,
  "requested_quantity": 1,
  "facility": {
    "id": 1,
    "name": "Cottage #1",
    "total_quantity": 1
  }
}
```

---

## 🎨 Frontend Considerations

### 1. Display Pending Bookings Differently

Since pending bookings don't reserve facilities, show them with a warning:

```jsx
{booking.booking_status === 'Pending' && (
  <Alert type="warning">
    <Icon name="clock" />
    Pending - Facility not yet reserved. Pay downpayment (50%) to secure this booking.
    <span className="text-danger">
      Other guests can book this facility while your booking is pending.
    </span>
  </Alert>
)}
```

### 2. Show Availability Correctly

When checking availability, don't count pending bookings:

```jsx
// ✅ Correct: Only confirmed bookings reduce availability
const availableFacilities = facilities.filter(f => {
  return f.bookings.filter(b =>
    ['Confirmed', 'Checked_In'].includes(b.booking_status)
  ).length < f.quantity;
});
```

### 3. Handle Auto-Cancellation Notifications

After payment, check response for cancelled bookings:

```jsx
const handlePayment = async (paymentData) => {
  const response = await api.post('/billings/123/payments', paymentData);

  if (response.data.auto_cancelled_bookings) {
    const { cancelled_count, cancelled_bookings } = response.data.auto_cancelled_bookings;

    if (cancelled_count > 0) {
      toast.info(
        `${cancelled_count} conflicting pending booking(s) were automatically cancelled.`,
        { duration: 5000 }
      );
    }
  }
};
```

### 4. Warn Users About Pending Status

```jsx
const PendingBookingWarning = () => (
  <Card className="warning-card">
    <h3>⚠️ Your booking is not yet confirmed</h3>
    <p>
      To secure your reservation, please pay at least 50% downpayment.
      <strong>While your booking is pending, other guests can still book this facility.</strong>
    </p>
    <Button onClick={handlePayDownpayment}>
      Pay Downpayment (₱{booking.billing.downpayment_amount.toLocaleString()})
    </Button>
  </Card>
);
```

---

## 🔍 Database Queries

### Find All Pending Bookings

```sql
SELECT
    b.id,
    b.booking_reference,
    b.guest_name,
    b.booking_status,
    b.check_in_datetime,
    b.check_out_datetime,
    bil.total_amount,
    bil.amount_paid,
    bil.balance,
    bil.payment_status
FROM bookings b
JOIN billings bil ON bil.billable_id = b.id AND bil.billable_type = 'App\\Models\\Booking'
WHERE b.booking_status = 'Pending'
  AND b.deleted_at IS NULL
ORDER BY b.check_in_datetime ASC;
```

### Find Auto-Cancelled Bookings

```sql
SELECT
    b.id,
    b.booking_reference,
    b.guest_name,
    b.booking_status,
    b.cancellation_reason,
    b.updated_at as cancelled_at
FROM bookings b
WHERE b.booking_status = 'Cancelled'
  AND b.cancellation_reason LIKE 'Auto-cancelled:%'
  AND b.deleted_at IS NULL
ORDER BY b.updated_at DESC
LIMIT 20;
```

### Check Facility Availability (Manual Query)

```sql
SELECT
    f.id,
    f.name,
    f.quantity as total_quantity,
    COUNT(DISTINCT bf.booking_id) as confirmed_bookings,
    SUM(bf.quantity) as reserved_quantity,
    f.quantity - COALESCE(SUM(bf.quantity), 0) as available_quantity
FROM facilities f
LEFT JOIN booking_facilities bf ON bf.facility_id = f.id
LEFT JOIN bookings b ON b.id = bf.booking_id
    AND b.booking_status IN ('Confirmed', 'Checked_In')
    AND b.check_in_datetime < '2025-11-26 12:00:00'
    AND b.check_out_datetime > '2025-11-24 14:00:00'
WHERE f.id = 1
  AND f.deleted_at IS NULL
GROUP BY f.id, f.name, f.quantity;
```

---

## 💡 Business Benefits

### For the Business:

1. **Maximize Bookings**: Multiple guests can create pending bookings for the same facility
2. **First-Come-First-Served**: Whoever pays first gets the facility
3. **No False Scarcity**: Pending bookings don't artificially reduce availability
4. **Automatic Cleanup**: Conflicting pending bookings are cleaned up automatically

### For Guests:

1. **Fair System**: Payment secures the booking, not just creation time
2. **Clear Status**: Know exactly when facility is secured (after payment)
3. **No Surprises**: Automatic cancellation with clear reason
4. **Competitive**: Encourages faster payment to secure desired facilities

---

## ⚠️ Important Notes

### 1. Refund Policy for Auto-Cancelled Bookings

If a pending booking that hasn't been paid is auto-cancelled:
- No refund needed (no payment made)
- Status: 'Cancelled'
- Billing status: 'cancelled'

If a pending booking with partial payment is auto-cancelled:
- Handle refund according to business policy
- Consider refunding partial payments automatically
- Or require manual refund processing

**Recommended: Add refund handling to auto-cancellation**

### 2. Notification System (Not Implemented)

Currently, no notifications are sent when bookings are auto-cancelled (as requested).

To add notifications later:
1. Send email to guest explaining auto-cancellation
2. Send SMS notification
3. Create in-app notification
4. Add to notification queue for batch sending

### 3. Grace Period (Optional)

Consider adding a grace period before auto-cancellation:
- Give pending bookings X minutes to complete payment
- Only cancel after grace period expires
- Implement in `BookingConflictResolutionService`

### 4. Reporting

Track auto-cancellation metrics:
- How many pending bookings get auto-cancelled daily?
- Which facilities have highest competition?
- Average time from booking creation to payment?

---

## 🎯 Summary

**Changes Implemented:**

1. ✅ Pending bookings don't reduce facility availability
2. ✅ Only Confirmed and Checked_In bookings reserve facilities
3. ✅ Auto-cancellation of conflicting pending bookings
4. ✅ Comprehensive logging for audit trail
5. ✅ No notifications sent (as requested)

**Files Modified:**
- `app/Services/FacilityAvailabilityService.php` (2 lines changed)

**Files Created:**
- `app/Services/BookingConflictResolutionService.php` (new service)

**Files Integrated:**
- `app/Services/BillingService.php` (added conflict resolution)

**Testing Checklist:**
- [ ] Create multiple pending bookings for same facility
- [ ] Verify all pending bookings can coexist
- [ ] Confirm first booking with payment
- [ ] Verify other pending bookings auto-cancel
- [ ] Check cancellation reasons in database
- [ ] Verify logs show conflict resolution
- [ ] Test with different facility quantities
- [ ] Test with partial overlaps

---

**Status:** ✅ Implemented and ready for testing
**Date:** November 24, 2025
**No notifications sent - Silent auto-cancellation as requested**
