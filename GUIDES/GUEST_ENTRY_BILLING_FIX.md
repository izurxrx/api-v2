# Guest Entry Billing Display Fix

**Date**: November 20, 2025
**Issue**: Guest entry showed `"billing": null` in API response even though booking had full payment

## Problem

When a booking was checked in, the guest entry was created and linked to the booking's billing via `guest_entry_id`. However, the API response showed:

```json
{
  "billing": null,
  "payment_status": "unpaid",
  "amount_paid": 0,
  "balance": 7500
}
```

Even though the booking had full payment (₱7,500 paid).

## Root Cause

The `GuestEntry::billing()` relationship method used **conditional logic** that checked `$this->entry_type` at runtime:

```php
public function billing()
{
    if ($this->entry_type === 'booking' && $this->booking_id) {
        return $this->hasOne(Billing::class, 'guest_entry_id');
    }
    return $this->morphOne(Billing::class, 'billable');
}
```

**Problem**: When Laravel's `load()` method calls the relationship, the conditional check doesn't work properly because the relationship definition needs to be static, not dynamic.

## Solution

Added a **custom attribute accessor** (`getBillingAttribute()`) that checks both billing sources:

```php
public function getBillingAttribute()
{
    // Try guest_entry_id first (for booking check-ins)
    $billing = Billing::where('guest_entry_id', $this->id)->first();

    if (!$billing) {
        // Fall back to polymorphic (for walk-ins)
        $billing = Billing::where('billable_type', GuestEntry::class)
                        ->where('billable_id', $this->id)
                        ->first();
    }

    return $billing;
}
```

This accessor:
1. **First** checks if billing is linked via `guest_entry_id` (booking check-ins)
2. **Then** falls back to polymorphic relationship (walk-ins)
3. Works with both direct access (`$guestEntry->billing`) and eager loading (`->load('billing')`)

## Files Modified

1. **app/Models/GuestEntry.php**
   - Updated `billing()` relationship method
   - Added `getBillingAttribute()` accessor

## Test Results

### Before Fix:
```json
{
  "billing": null,
  "payment_status": "unpaid",
  "amount_paid": 0
}
```

### After Fix:
```json
{
  "billing": {
    "id": 49,
    "billing_number": "BILL-2025-00010",
    "total_amount": 7500,
    "amount_paid": 7500,
    "balance": 0,
    "payment_status": "Paid",
    "payments": [
      {
        "id": 32,
        "amount": "3,750.00",
        "payment_method": "cash"
      },
      {
        "id": 33,
        "amount": "3,750.00",
        "payment_method": "cash"
      }
    ]
  },
  "payment_status": "paid",
  "amount_paid": 7500,
  "balance": 0
}
```

## How It Works Now

### Booking Check-In Flow:

```
1. User creates booking → Billing created with booking
2. User pays downpayment → Payment recorded in billing
3. User checks in booking → Guest entry created
4. Billing linked to guest entry → billing.guest_entry_id = guest_entry.id
5. API returns guest entry → Billing automatically included! ✅
```

### Data Structure:

```
┌─────────┐
│ Booking │
│  ID: 92 │
└────┬────┘
     │
     │ billable_type + billable_id
     ↓
┌──────────────┐        ┌──────────────┐
│   Billing    │◄───────│ Guest Entry  │
│   ID: 49     │        │   ID: 90     │
│              │        │              │
│ billable_type: Booking│              │
│ billable_id: 92       │              │
│ guest_entry_id: 90 ───┤              │
└──────┬───────┘        └──────────────┘
       │
       ↓
┌──────────────┐
│  Payments    │
│  - PAY-001   │
│  - PAY-002   │
└──────────────┘
```

Both `$booking->billing` and `$guestEntry->billing` return the **same billing record**!

## Verification

Run the test script:
```bash
php test/test_checkin_billing_response.php
```

**Expected Output**:
```
✅ SUCCESS! Billing is included in the API response.

The frontend will now receive:
  - Full billing information
  - Payment history
  - Payment status
```

## Impact

✅ **Guest entries from booking check-ins** now show full billing and payment information
✅ **Walk-in guest entries** continue to work with polymorphic relationships
✅ **API responses** include complete payment data
✅ **Frontend** can now display payment status correctly
✅ **No breaking changes** to existing functionality

## Related Issues Fixed

- Guest entry billing showing null ✅
- Payment status showing "unpaid" when fully paid ✅
- Amount paid showing 0 when payments exist ✅
- Payment history not accessible from guest entry ✅

## Future Bookings

All **new** booking check-ins will automatically include billing in the API response. No additional changes needed!
