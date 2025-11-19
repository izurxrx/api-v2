# Checkout Workflow Analysis

## 🔍 Current Status

### Bookings
- **Current Status**: 1 booking with status `Checked_In`
- **Issue**: No bookings with status `Checked_Out`
- **Revenue Impact**: ❌ Not counted in revenue reports (requires `Checked_Out` status)

### Guest Entries (Walk-ins)
- **Current Status**: 1 guest entry with `is_checked_out = 0` (false)
- **Issue**: No checked-out guest entries
- **Revenue Impact**: ❌ Not counted in revenue reports (requires `is_checked_out = true`)

---

## 📋 Correct Workflow Analysis

### BOOKING WORKFLOW

#### Status Flow
```
1. Pending       → Created, awaiting downpayment
2. Confirmed     → Downpayment paid (50% minimum)
3. Checked_In    → Guest arrived and checked in
4. Checked_Out   → Guest left (✅ REVENUE COUNTED)
5. Cancelled     → Booking cancelled
6. No_Show       → Guest didn't show up
```

#### Checkout Endpoint
```
POST /api/bookings/{id}/check-out
```

#### What It Does
✅ Sets `booking_status = 'Checked_Out'`
✅ Records `actual_check_out_datetime`
✅ Records `checked_out_by` (staff ID)
✅ Calculates overstay fees (if applicable)
✅ Updates billing with additional charges

#### Required Validation
- Booking must be in `Checked_In` status
- Only checked-in guests can check out

#### Code Location
`app/Http/Controllers/Api/BookingController.php` (line 567-640)

---

### GUEST ENTRY (WALK-IN) WORKFLOW

#### Status Flow
```
1. Created           → Walk-in registered, payment collected
2. is_checked_out=1  → Guest left (✅ REVENUE COUNTED)
```

#### Checkout Endpoint
```
POST /api/guest-monitoring/{id}/checkout
```

#### What It Does
✅ Sets `is_checked_out = true`
✅ Records `exit_date`, `exit_time`, `checkout_datetime`
✅ Records `checked_out_by` (staff ID)
✅ Updates billing status to `completed`
✅ Records checkout notes

#### Required Validation
- Guest must NOT already be checked out
- Payment must be complete (balance = 0)
- Checkout date cannot be before check-in date
- Checkout datetime cannot be in the future (1-hour grace period)

#### Code Location
`app/Http/Controllers/Api/GuestMonitoringController.php` (line 370-476)

---

## 🎯 Revenue Report Requirements

### For Bookings to Count in Revenue
**Required:**
- ✅ `booking_status = 'Checked_Out'`
- ✅ `check_out_datetime` within report period

**Current Issue:**
- ❌ Booking ID 92 is still `Checked_In`, not `Checked_Out`

### For Walk-ins to Count in Revenue
**Required:**
- ✅ `is_checked_out = true` (value 1)
- ✅ `checkout_datetime` within report period

**Current Issue:**
- ❌ Guest Entry ID 108 has `is_checked_out = 0` (was manually fixed earlier but needs proper checkout)

---

## 🔧 How to Fix Current Data

### Option 1: Use Proper Checkout Endpoints

**For Booking ID 92:**
```bash
POST /api/bookings/92/check-out
Content-Type: application/json

{
  "notes": "Guest departed"
}
```

**For Guest Entry ID 108:**
```bash
POST /api/guest-monitoring/108/checkout
Content-Type: application/json

{
  "exit_date": "2025-11-14",
  "exit_time": "17:00",
  "notes": "Guest departed"
}
```

### Option 2: Manual Database Update (Testing Only)

**Booking:**
```sql
UPDATE bookings 
SET booking_status = 'Checked_Out',
    actual_check_out_datetime = '2025-11-15 17:00:00',
    checked_out_by = 1
WHERE id = 92;
```

**Guest Entry:**
```sql
UPDATE guest_entries 
SET is_checked_out = 1,
    exit_date = '2025-11-14',
    exit_time = '17:00',
    checkout_datetime = '2025-11-14 17:00:00',
    checked_out_by = 1
WHERE id = 108;

UPDATE billings
SET billing_status = 'completed'
WHERE billable_type = 'App\\Models\\GuestEntry'
AND billable_id = 108;
```

---

## ✅ Verification Checklist

After checkout, verify:

### For Bookings
- [ ] `booking_status` changed from `Checked_In` to `Checked_Out`
- [ ] `actual_check_out_datetime` is recorded
- [ ] `checked_out_by` contains staff user ID
- [ ] Billing updated with any additional charges
- [ ] Appears in revenue report when date range includes checkout date

### For Guest Entries
- [ ] `is_checked_out` changed from `0` to `1`
- [ ] `exit_date`, `exit_time`, `checkout_datetime` are recorded
- [ ] `checked_out_by` contains staff user ID
- [ ] Billing status changed to `completed`
- [ ] Appears in revenue report when date range includes checkout date

---

## 🚨 Common Issues

### Issue 1: "Cannot checkout with outstanding balance"
**Cause**: Guest entry has unpaid balance
**Solution**: Process full payment before checkout
```bash
POST /api/payments
{
  "billing_id": <billing_id>,
  "amount": <balance_amount>,
  "payment_method": "cash",
  "payment_date": "2025-11-17"
}
```

### Issue 2: "Only checked-in bookings can be checked out"
**Cause**: Booking is not in `Checked_In` status
**Solution**: Check in the booking first
```bash
POST /api/guest-monitoring/check-in-booking/{booking_id}
{
  "actual_guests": 5,
  "check_in_notes": "Guest arrived"
}
```

### Issue 3: "Guest has already been checked out"
**Cause**: Trying to checkout twice
**Solution**: Check current status, no action needed if already checked out

### Issue 4: "Cannot checkout before check-in date"
**Cause**: Exit date is before entry date
**Solution**: Use correct exit date (same day or after entry date)

---

## 📊 Revenue Report Integration

### How Revenue is Calculated

**Bookings:**
```php
// Gets booking IDs that are Checked_Out
$bookingIds = Booking::where('booking_status', 'Checked_Out')
    ->whereBetween('check_out_datetime', [$dateFrom, $dateTo])
    ->pluck('id');

// Sums amount_paid from their billings
$revenue = Billing::where('billable_type', Booking::class)
    ->whereIn('billable_id', $bookingIds)
    ->whereIn('payment_status', ['paid', 'partial'])
    ->sum('amount_paid');
```

**Guest Entries:**
```php
// Gets guest entry IDs that are checked out
$guestEntryIds = GuestEntry::where('is_checked_out', true)
    ->whereBetween('checkout_datetime', [$dateFrom, $dateTo])
    ->pluck('id');

// Sums amount_paid from their billings
$revenue = Billing::where('billable_type', GuestEntry::class)
    ->whereIn('billable_id', $guestEntryIds)
    ->whereIn('payment_status', ['paid', 'partial'])
    ->sum('amount_paid');
```

**Key Points:**
- ✅ Only completed transactions count (checked out)
- ✅ Uses checkout date to determine period
- ✅ Only counts paid or partially paid billings
- ✅ Cancelled transactions excluded

---

## 🎓 For Your Panelists

### Demonstration Flow

**1. Create Booking**
```
POST /api/bookings
→ Status: Pending
```

**2. Pay Downpayment**
```
POST /api/bookings/{id}/downpayment
→ Status: Confirmed
```

**3. Check In**
```
POST /api/guest-monitoring/check-in-booking/{id}
→ Status: Checked_In
```

**4. Pay Remaining Balance**
```
POST /api/payments
→ Billing: Paid
```

**5. Check Out** ⭐
```
POST /api/bookings/{id}/check-out
→ Status: Checked_Out ✅ REVENUE COUNTED
```

**6. Generate Report**
```
GET /api/reports/revenue?date_preset=today
→ Shows revenue from checked-out transactions
```

---

## 📝 Summary

**Current Situation:**
- ✅ Checkout endpoints exist and work correctly
- ✅ All validations in place
- ❌ Current data not checked out yet (still in-progress)
- ❌ No revenue shown because no completed transactions

**To Show Revenue:**
1. Complete checkout process for existing transactions
2. Or create new test transactions and complete full workflow
3. Then generate revenue report

**Workflow is CORRECT**, just needs to be completed! 🎉
