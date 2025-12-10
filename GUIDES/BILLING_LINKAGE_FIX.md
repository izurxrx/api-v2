# Billing Linkage Fix: Connecting Bookings and Guest Entries

**Date**: November 20, 2025
**Issue**: Guest entries created from booking check-ins were not linked to the booking's billing and payments

## Problem Description

When a booking was checked in through `GuestMonitoringController::checkInBooking()`, the system:
- ✅ Created a `GuestEntry` record with `booking_id` reference
- ✅ Copied all booking details (facilities, services, amounts)
- ✅ Updated booking status to "Checked_In"
- ❌ **DID NOT link the guest entry to the booking's billing**

This meant:
- Guest entries had no access to billing information
- Guest entries had no access to payment history
- The same transaction was tracked in two separate places
- Revenue reports and billing queries were incomplete

## Solution Implemented

We implemented **Option 1: Dual Linkage System** that allows a single billing to be accessed from both the booking and guest entry.

### Changes Made

#### 1. Database Migration
**File**: `database/migrations/2025_11_20_095200_add_guest_entry_id_to_billings_table.php`

Added `guest_entry_id` column to the `billings` table:
```php
$table->unsignedBigInteger('guest_entry_id')->nullable()->after('billable_id');
$table->foreign('guest_entry_id')->references('id')->on('guest_entries')->onDelete('set null');
$table->index('guest_entry_id');
```

**How it works**:
- `billable_type` + `billable_id`: Links billing to the **booking** (original polymorphic relationship)
- `guest_entry_id`: Links billing to the **guest entry** (new direct relationship)
- When a booking is checked in, both fields are populated

#### 2. Billing Model Update
**File**: `app/Models/Billing.php`

- Added `guest_entry_id` to `$fillable` array
- Added `guestEntry()` relationship method:
```php
public function guestEntry()
{
    return $this->belongsTo(GuestEntry::class, 'guest_entry_id');
}
```

#### 3. GuestEntry Model Update
**File**: `app/Models/GuestEntry.php`

Updated the `billing()` relationship to handle both walk-ins and booking check-ins:
```php
public function billing()
{
    // For booking check-ins: Use guest_entry_id link
    if ($this->entry_type === 'booking' && $this->booking_id) {
        return $this->hasOne(Billing::class, 'guest_entry_id');
    }

    // For walk-ins: Use polymorphic relationship
    return $this->morphOne(Billing::class, 'billable');
}
```

#### 4. GuestMonitoringController Update
**File**: `app/Http/Controllers/Api/GuestMonitoringController.php` (lines 1085-1096)

Added billing linkage during check-in:
```php
// Link billing to guest entry
if ($booking->billing) {
    $booking->billing->update([
        'guest_entry_id' => $guestEntry->id,
    ]);

    Log::info('Billing linked to guest entry', [
        'billing_id' => $booking->billing->id,
        'guest_entry_id' => $guestEntry->id,
        'booking_id' => $booking->id,
    ]);
}
```

## How It Works

### Before Check-In (Booking Created)
```
┌─────────┐
│ Booking │
└────┬────┘
     │
     │ billable_type + billable_id
     ↓
┌──────────┐
│ Billing  │
│          │
│ guest_entry_id: NULL
└──────────┘
```

### After Check-In (Guest Entry Created)
```
┌─────────┐                    ┌──────────────┐
│ Booking │                    │ Guest Entry  │
└────┬────┘                    └──────┬───────┘
     │                                │
     │ billable_type + billable_id    │
     │                                │
     └────────────┬───────────────────┘
                  ↓
            ┌──────────┐
            │ Billing  │
            │          │
            │ billable_type: Booking
            │ billable_id: 123
            │ guest_entry_id: 456  ← NEW!
            └──────────┘
                  │
                  ↓
            ┌──────────┐
            │ Payments │
            └──────────┘
```

### Access Patterns

**From Booking:**
```php
$booking->billing                // Works (polymorphic)
$booking->billing->payments      // Works
```

**From Guest Entry:**
```php
$guestEntry->billing             // Works (via guest_entry_id)
$guestEntry->billing->payments   // Works
```

**Both access the SAME billing!**

## Testing

Run the test script to verify the linkage:
```bash
php test/test_billing_linkage.php
```

**Test Results**: ✅ ALL TESTS PASSED
- Booking can access billing: ✅
- Guest Entry can access billing: ✅
- Same billing instance: ✅
- Payments accessible from guest entry: ✅
- Data integrity maintained: ✅
- Reverse relationship (billing → guest entry): ✅

## Benefits

1. **Single Source of Truth**: One billing record tracks all payments for both booking and guest entry
2. **Payment Continuity**: Downpayments made during booking are visible during check-in
3. **Simplified Queries**: Revenue reports can use either bookings or guest_entries table
4. **Data Integrity**: No duplicate billing records or orphaned payments
5. **Backward Compatible**: Existing walk-in guest entries (without bookings) continue to work

## Migration Steps

1. **Run Migration**:
   ```bash
   php artisan migrate
   ```

2. **No Data Migration Needed**:
   - Existing billings remain unchanged
   - The `guest_entry_id` field will be populated automatically when future bookings are checked in
   - Old guest entries from bookings won't have this link (acceptable)

3. **Optional: Backfill Old Data** (if needed):
   ```sql
   UPDATE billings b
   JOIN guest_entries ge ON ge.booking_id = b.billable_id
       AND b.billable_type = 'App\\Models\\Booking'
   SET b.guest_entry_id = ge.id
   WHERE b.guest_entry_id IS NULL;
   ```

## API Response Changes

### Guest Entry Resource
When loading a guest entry with billing, you'll now see:
```json
{
  "id": 88,
  "entry_reference": "ENTRY-123",
  "entry_type": "booking",
  "booking_id": 91,
  "billing": {
    "id": 48,
    "billing_number": "BILL-2025-00009",
    "total_amount": 4500.00,
    "amount_paid": 4500.00,
    "balance": 0.00,
    "payments": [
      {
        "payment_number": "PAY-001",
        "amount": 2250.00,
        "payment_method": "cash"
      }
    ]
  }
}
```

## Files Modified

1. `database/migrations/2025_11_20_095200_add_guest_entry_id_to_billings_table.php` - NEW
2. `app/Models/Billing.php` - Updated fillable and added guestEntry() relationship
3. `app/Models/GuestEntry.php` - Updated billing() relationship logic
4. `app/Http/Controllers/Api/GuestMonitoringController.php` - Added billing linkage on check-in
5. `test/test_billing_linkage.php` - NEW test script

## Related Issues Fixed

✅ Guest entries now show payment history from booking
✅ Revenue reports include all payments (booking + guest entry)
✅ Billing queries work from both tables
✅ No duplicate billing records created
✅ Payment status correctly reflects booking downpayments

## Future Considerations

- The system now supports two types of guest entries:
  1. **Walk-ins** (`entry_type = 'walk_in'`): Use polymorphic billing relationship
  2. **Booking check-ins** (`entry_type = 'booking'`): Use guest_entry_id link

- Both types work seamlessly with the same billing/payment infrastructure
- Reports can filter by `entry_type` if needed

## Conclusion

This fix ensures that when a booking is checked in, the guest entry maintains full access to the booking's billing and payment history. The solution maintains backward compatibility while providing a clean, efficient way to track financial transactions across the booking lifecycle.
