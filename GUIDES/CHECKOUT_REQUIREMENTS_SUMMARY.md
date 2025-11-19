# Checkout Requirements by Entry Type - Implementation Summary

## 📋 Overview

Updated checkout logic to differentiate between entry types and booking types. Only **Package bookings** require checkout with overtime tracking. Walk-ins and Swimming bookings complete transactions without formal checkout.

**Date:** November 19, 2025  
**Status:** ✅ Complete

---

## 🎯 Checkout Matrix

| Entry Source | Entry Type | Booking Type | Checkout Required? | Overtime? | Transaction Completes |
|--------------|------------|--------------|-------------------|-----------|----------------------|
| **Walk-in** (Guest Monitoring) | `Walk_In` | N/A | ❌ No | ❌ No | Upon payment |
| **Swimming Booking** (Booking → Check-in) | `booking` | `Swimming` | ❌ No | ❌ No | Upon check-in + payment |
| **Package Booking** (Booking → Check-in) | `booking` | `Package` | ✅ Yes | ✅ Yes (opt-in) | Upon checkout + payment |

---

## 🔍 Business Logic

### **Walk-in Entries**
- **Created via:** `POST /api/guest-monitoring` (directly)
- **entry_type:** `'Walk_In'`
- **booking_id:** `null`
- **Use Case:** Day guests, casual visits
- **Payment:** Immediate (before using facilities)
- **Checkout:** ❌ Not required
- **Exit:** Leave freely
- **Transaction Complete:** When balance = 0
- **Facility Release:** Auto after scheduled time OR manual by staff

### **Swimming Bookings**
- **Created via:** `POST /api/bookings` with `booking_type = 'Swimming'`
- **Then checked in via:** `POST /api/guest-monitoring/check-in-booking/{id}`
- **entry_type:** `'booking'` (after check-in)
- **booking_type:** `'Swimming'`
- **Use Case:** Reserved day-use cottages/facilities (8am-8pm, etc.)
- **Payment:** Deposit at booking, balance at check-in (before using)
- **Checkout:** ❌ Not required (day use only)
- **Exit:** Leave freely after scheduled time
- **Transaction Complete:** After check-in + full payment
- **Facility Release:** Auto after scheduled checkout time

**Why no checkout?**
- Fixed time slots (Day Rate, Night Rate)
- Similar to walk-ins but with reservation
- No overnight stays
- No late checkout concern (day use only)

### **Package Bookings**
- **Created via:** `POST /api/bookings` with `booking_type = 'Package'`
- **Then checked in via:** `POST /api/guest-monitoring/check-in-booking/{id}`
- **entry_type:** `'booking'` (after check-in)
- **booking_type:** `'Package'`
- **Use Case:** Multi-day stays, overnight packages, events
- **Payment:** Deposit at booking, balance at/after checkout
- **Checkout:** ✅ Required
- **Overtime:** ✅ Opt-in calculation available
- **Exit:** Staff records actual checkout time
- **Transaction Complete:** After checkout + final payment
- **Facility Release:** At actual checkout

**Why checkout needed?**
- Multi-day or overnight stays
- Higher value transactions
- Late checkout possible (overtime)
- Accountability and tracking required

---

## 📝 Implementation Details

### **GuestMonitoringController.php - checkout() Method**

Added two validation layers:

```php
// Layer 1: Block Walk-ins
if ($guestEntry->entry_type === 'Walk_In') {
    return response()->json([
        'status' => 'error',
        'message' => 'Walk-in guests do not require checkout. Transaction is complete upon payment.',
        'entry_type' => 'Walk_In',
    ], 403);
}

// Layer 2: Block Swimming bookings
if ($guestEntry->booking_id) {
    $booking = $guestEntry->booking;
    
    if ($booking && $booking->booking_type === 'Swimming') {
        return response()->json([
            'status' => 'error',
            'message' => 'Swimming bookings do not require checkout. These are day-use bookings with fixed time slots.',
            'booking_type' => 'Swimming',
        ], 403);
    }
}

// Continue with checkout for Package bookings only
```

### **GuestMonitoringController.php - previewCheckout() Method**

Same validation logic applied to preview endpoint.

### **Billing.php Model - updateBillableStatus() Method**

Added auto-completion for Swimming booking transactions:

```php
// For Guest Entries
if ($billable instanceof GuestEntry) {
    // Walk-in completion
    if ($billable->entry_type === 'Walk_In' && $this->balance <= 0) {
        $this->update([
            'billing_status' => self::STATUS_COMPLETED,
            'payment_status' => self::PAYMENT_PAID,
            'paid_at' => now(),
        ]);
    }
    
    // Swimming booking completion (NEW)
    if ($billable->booking_id && $this->balance <= 0) {
        $booking = $billable->booking;
        
        if ($booking && $booking->booking_type === 'Swimming') {
            $this->update([
                'billing_status' => self::STATUS_COMPLETED,
                'payment_status' => self::PAYMENT_PAID,
                'paid_at' => now(),
            ]);
        }
    }
    
    // Package bookings: Checkout required
}
```

---

## 🔄 System Flows

### **Walk-in Flow:**
```
1. Guest arrives
2. Staff creates walk-in entry (POST /guest-monitoring)
3. System calculates charges
4. Guest pays full amount
5. ✅ Transaction COMPLETE (billing closes)
6. Guest uses facilities
7. Guest leaves freely
```

### **Swimming Booking Flow:**
```
1. Guest creates booking (booking_type = 'Swimming')
2. Guest pays deposit
3. Booking status: Pending → Confirmed
4. Guest arrives on scheduled date
5. Staff checks in booking (creates guest entry)
6. Guest pays REMAINING BALANCE
7. ✅ Transaction COMPLETE (billing closes)
8. Guest uses facilities (cottages, pool, etc.)
9. Guest leaves freely after scheduled time
10. Facilities auto-released after checkout time
```

### **Package Booking Flow:**
```
1. Guest creates booking (booking_type = 'Package')
2. Guest pays deposit
3. Booking status: Pending → Confirmed
4. Guest arrives
5. Staff checks in booking (creates guest entry)
6. Guest uses facilities (multi-day/overnight)
7. Guest ready to leave
8. Staff processes checkout (POST /guest-monitoring/{id}/checkout)
9. System calculates overtime (opt-in)
10. Guest pays final balance (if any)
11. ✅ Transaction COMPLETE
12. Facilities released immediately
```

---

## 🚨 Key Differences

### **Swimming vs Package Bookings:**

| Aspect | Swimming | Package |
|--------|----------|---------|
| **Duration** | Same day (8am-8pm) | Multi-day/overnight |
| **Checkout** | ❌ No | ✅ Yes |
| **Payment Timing** | Balance at check-in | Balance at checkout |
| **Overtime** | ❌ No (fixed slot) | ✅ Yes (opt-in) |
| **Exit Tracking** | No (scheduled time) | Yes (actual time) |
| **Facility Release** | Auto after scheduled time | At actual checkout |
| **Like...** | Beach umbrella rental | Hotel room |

---

## 🔌 API Behavior

### **Checkout Endpoints:**
```
POST /api/guest-monitoring/{id}/checkout
POST /api/guest-monitoring/{id}/preview-checkout
```

**Responses:**

#### ✅ Package Booking (Success)
```json
{
    "status": "success",
    "message": "Guest checked out successfully",
    "data": {
        "booking_type": "Package",
        "overtime": {...}
    }
}
```

#### ❌ Walk-in (403 Forbidden)
```json
{
    "status": "error",
    "message": "Walk-in guests do not require checkout. Transaction is complete upon payment. Guests may leave the facility freely.",
    "entry_type": "Walk_In"
}
```

#### ❌ Swimming Booking (403 Forbidden)
```json
{
    "status": "error",
    "message": "Swimming bookings do not require checkout. These are day-use bookings with fixed time slots. Transaction completes after check-in and payment.",
    "booking_type": "Swimming",
    "entry_type": "booking"
}
```

---

## 🎨 Frontend Requirements

### **Before Showing Checkout Button:**

```javascript
// Check entry type first
if (guestEntry.entry_type === 'Walk_In') {
    // Hide checkout, show "Transaction Complete" if paid
    return;
}

// For booking entries, check booking type
if (guestEntry.booking_id) {
    const booking = await fetchBooking(guestEntry.booking_id);
    
    if (booking.booking_type === 'Swimming') {
        // Hide checkout, show "Day Use - No Checkout Required"
        return;
    }
    
    if (booking.booking_type === 'Package') {
        // Show checkout button
        enableCheckout();
    }
}
```

### **Status Indicators:**

**Walk-in:**
```html
<Badge color="green">Transaction Complete</Badge>
<p>Guest may leave anytime</p>
```

**Swimming Booking:**
```html
<Badge color="blue">Checked In - Day Use</Badge>
<p>Scheduled until: 8:00 PM</p>
<p>No checkout required</p>
```

**Package Booking:**
```html
<Badge color="orange">Checked In</Badge>
<Button onclick="checkout()">Checkout Guest</Button>
```

---

## 📊 Database Fields

### **What Gets Set:**

| Field | Walk-in | Swimming | Package |
|-------|---------|----------|---------|
| `entry_type` | `'Walk_In'` | `'booking'` | `'booking'` |
| `booking_id` | `null` | Set | Set |
| `booking.booking_type` | N/A | `'Swimming'` | `'Package'` |
| `is_checked_out` | `false` | `false` | `true` (at checkout) |
| `checkout_datetime` | `null` | `null` | Set (at checkout) |
| `exit_date` | `null` | `null` | Set (at checkout) |
| `exit_time` | `null` | `null` | Set (at checkout) |
| `billing.billing_status` | `'completed'` (on payment) | `'completed'` (on payment) | `'completed'` (at checkout) |

---

## ✅ Testing Checklist

### **Walk-in Tests:**
- [ ] Create walk-in → Pay → Billing completes immediately
- [ ] Attempt checkout → Returns 403 error
- [ ] Frontend hides checkout button

### **Swimming Booking Tests:**
- [ ] Create Swimming booking → Pay deposit
- [ ] Check-in booking → Pay balance → Billing completes
- [ ] Attempt checkout → Returns 403 error
- [ ] Frontend hides checkout button
- [ ] Shows "Day Use" indicator

### **Package Booking Tests:**
- [ ] Create Package booking → Pay deposit
- [ ] Check-in booking → Guest uses facilities
- [ ] Preview checkout → Shows overtime if late
- [ ] Checkout with/without overtime → Works
- [ ] Billing completes after checkout
- [ ] Frontend shows checkout button

---

## 📈 Reporting Considerations

### **Duration Tracking:**

**Walk-ins:**
- No checkout time recorded
- Use estimated duration from rate
- Mark as "Estimated" in reports

**Swimming Bookings:**
- No checkout time recorded
- Use scheduled checkout time from booking
- Duration = check_in_datetime to check_out_datetime (scheduled)
- Mark as "Scheduled" in reports

**Package Bookings:**
- Actual checkout time recorded
- Duration = check_in_datetime to checkout_datetime (actual)
- Mark as "Actual" in reports

### **Facility Utilization:**

```php
if ($entry->entry_type === 'Walk_In') {
    $duration = $entry->entranceRate->duration_hours ?? 12; // Estimated
} elseif ($entry->booking && $entry->booking->booking_type === 'Swimming') {
    $duration = $entry->booking->check_out_datetime->diffInHours($entry->check_in_datetime); // Scheduled
} else {
    $duration = $entry->checkout_datetime->diffInHours($entry->check_in_datetime); // Actual
}
```

---

## 🔮 Future Enhancements

### **Automatic Facility Release:**
- Cron job to auto-release facilities after scheduled time
- Only for Walk-ins and Swimming bookings
- Package bookings released at actual checkout

### **Scheduled Checkout Warnings:**
- Alert staff 30 mins before Swimming booking end time
- Remind to check if guest has left
- Option to extend booking if needed

### **Smart Duration Estimates:**
- Machine learning on actual walk-in durations
- Better reporting estimates
- Facility availability predictions

---

## 📞 Summary

**What Changed:**
1. ✅ Walk-ins: No checkout (already done)
2. ✅ Swimming bookings: No checkout (NEW)
3. ✅ Package bookings: Checkout required (unchanged)

**Why:**
- Walk-ins = casual day guests
- Swimming = reserved day-use slots
- Package = multi-day/overnight needs tracking

**Impact:**
- Clearer transaction flows
- Better matches real-world operations
- Reduced unnecessary checkouts

---

**Implementation Complete** ✅  
**Ready for Testing** 🧪  
**Documentation Updated** 📚
