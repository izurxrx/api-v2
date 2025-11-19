# Opt-in Overtime System - Quick Reference Guide

## 🎯 Key Concept: Overtime is OPTIONAL (Bookings Only)

**Default Behavior:** Overtime charges are **NOT automatically applied**. Staff must explicitly choose to apply them.

⚠️ **IMPORTANT:** Overtime system **only applies to bookings**. Walk-in guests do not have checkout and therefore no overtime charges.

---

## 🚨 Walk-in vs Booking Differences

| Feature | Walk-ins | Bookings |
|---------|----------|----------|
| **Checkout Required** | ❌ No | ✅ Yes |
| **Overtime Charges** | ❌ Not applicable | ✅ Opt-in available |
| **Transaction Complete** | Upon payment | Upon checkout + payment |
| **Guest Exit** | Leave freely | Staff records exit time |
| **Billing Status** | Closes immediately after payment | Closes after checkout |

---

## Frontend Implementation Workflow

### Step 1: Check Entry Type
```javascript
// Determine if this is a walk-in or booking guest entry
if (guestEntry.entry_type === 'Walk_In') {
    // Walk-ins: No checkout, no overtime
    // Hide checkout button
    showMessage('Walk-in guests may leave freely. No checkout required.');
    return;
}

// For bookings: Continue with checkout flow
```

### Step 2: Preview Checkout (Bookings Only)
```javascript
// Always call preview to show potential overtime
const preview = await api.post(`/guest-monitoring/${id}/preview-checkout`, {
    exit_datetime: selectedTime
});

console.log(preview.data);
// {
//   overtime: {
//     has_overtime: true,
//     total: 150.00,
//     formatted_total: "₱150.00",
//     details: [...],
//     requires_explicit_application: true,
//     note: "To apply these charges, pass apply_overtime=true in checkout request"
//   },
//   new_billing: { ... }
// }
```

### Step 3: Show Overtime Dialog (If Detected)
```javascript
if (preview.data.overtime.has_overtime) {
    const userChoice = await showOvertimeDialog({
        currentBalance: preview.data.current_billing.balance,
        overtimeTotal: preview.data.overtime.total,
        overtimeDetails: preview.data.overtime.details,
        newBalance: preview.data.new_billing.balance
    });
    
    // userChoice will be 'skip' or 'apply'
}
```

**Example Dialog:**
```
┌─────────────────────────────────────────┐
│ ⏰ Overtime Detected                     │
├─────────────────────────────────────────┤
│ Guest exceeded facility time            │
│                                         │
│ Current Balance: ₱0.00                  │
│                                         │
│ Potential Overtime Charges:             │
│ • Swimming Pool: 1.5 hrs × ₱100         │
│   = ₱150.00                             │
│                                         │
│ Total Overtime: ₱150.00                 │
│                                         │
│ ⚠️ These charges are OPTIONAL           │
│ Would you like to apply them?           │
│                                         │
│ Reasons to skip:                        │
│ - Customer complaint/gesture            │
│ - VIP/special circumstance             │
│ - Minor overstay (within reason)       │
│                                         │
│ [Checkout Without Overtime]             │
│ [Apply Overtime & Collect Payment]      │
└─────────────────────────────────────────┘
```

### Step 4: Checkout Based on Decision

#### Option A: Skip Overtime (Default)
```javascript
// User clicked "Checkout Without Overtime"
const response = await api.post(`/guest-monitoring/${id}/checkout`, {
    exit_date: "2025-11-19",
    exit_time: "15:45",
    apply_overtime: false,  // Explicitly skip (or just omit this field)
    notes: "Overtime waived - customer service gesture"
});

// Result: Guest checks out, NO overtime charges added
```

#### Option B: Apply Overtime
```javascript
// User clicked "Apply Overtime & Collect Payment"
const response = await api.post(`/guest-monitoring/${id}/checkout`, {
    exit_date: "2025-11-19",
    exit_time: "15:45",
    apply_overtime: true,  // ⭐ REQUIRED to actually charge overtime
    notes: "Customer agreed to overtime charges"
});

// If overtime applied and balance > 0, redirect to payment
if (response.data.overtime.has_overtime && response.data.overtime.total > 0) {
    // Redirect to payment page
    window.location.href = `/payment?billing_id=${response.data.data.billing.id}`;
}
```

---

## API Request/Response Examples

### ❌ Walk-in Guest Checkout (NOT AVAILABLE)

Walk-in guests **do not have checkout endpoints**. Attempting to checkout a walk-in will return an error:

**Request:**
```http
POST /api/guest-monitoring/123/checkout
```

**Response (403 Forbidden):**
```json
{
    "status": "error",
    "message": "Walk-in guests do not require checkout. Transaction is complete upon payment. Guests may leave the facility freely.",
    "entry_type": "Walk_In"
}
```

---

### ✅ Booking Guest Checkout (AVAILABLE)

**Preview Request:**
```http
POST /api/guest-monitoring/123/preview-checkout
Content-Type: application/json

{
    "exit_datetime": "2025-11-19 15:45:00"
}
```

**Preview Response:**
```json
{
    "status": "success",
    "message": "Checkout preview generated",
    "data": {
        "guest_entry_id": 123,
        "current_billing": {
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
                    "rate_name": "Day Rate",
                    "overtime_hours": 1.5,
                    "extension_fee": 100.00,
                    "final_amount": 150.00,
                    "formatted_amount": "₱150.00"
                }
            ],
            "requires_explicit_application": true,
            "note": "To apply these charges, pass apply_overtime=true in checkout request"
        },
        "new_billing": {
            "balance": 150.00,
            "formatted_balance": "₱150.00",
            "payment_required": true
        }
    }
}
```

**Checkout Request (Skip Overtime):**
```http
POST /api/guest-monitoring/123/checkout
Content-Type: application/json
Authorization: Bearer {token}

{
    "exit_date": "2025-11-19",
    "exit_time": "15:45",
    "apply_overtime": false,
    "notes": "Overtime waived - booking guest entry"
}
```

**Note:** Only works for booking guest entries (entry_type = 'booking'). Walk-ins will return 403 error.

**Checkout Request (Apply Overtime):**
```http
POST /api/guest-monitoring/123/checkout
Content-Type: application/json

{
    "exit_date": "2025-11-19",
    "exit_time": "15:45",
    "apply_overtime": true,
    "notes": "Customer agreed to pay overtime"
}
```

### ⚠️ Alternative Booking Checkout Routes (Deprecated)

These routes are deprecated. Use `/guest-monitoring/{id}/checkout` instead:

**Preview Request (Deprecated):**
```http
POST /api/bookings/456/preview-check-out
Content-Type: application/json

{
    "actual_checkout_datetime": "2025-11-19 15:45:00"
}
```

**Checkout Request (Deprecated):**
```http
POST /api/bookings/456/check-out
Content-Type: application/json

{
    "actual_checkout_datetime": "2025-11-19 15:45:00",
    "apply_overtime": true,
    "notes": "Guest acknowledged overtime charges"
}
```

**Note:** These endpoints still work but are marked for deprecation. Use the guest-monitoring endpoints for unified checkout flow.

---

## Key Differences from Auto-Apply System

| Aspect | Auto-Apply (Old) | Opt-in (Current) |
|--------|------------------|------------------|
| **Default** | Overtime charged automatically | No overtime unless requested |
| **Staff Control** | Can only waive with special permission | Full control on every checkout |
| **Checkout Flow** | Forced to pay or get error | Can proceed without overtime |
| **Use Case** | Strict policy enforcement | Flexible customer service |
| **Preview** | Shows what WILL be charged | Shows what CAN be charged |
| **Request Field** | N/A (always applied) | `apply_overtime: true` required |

---

## Common Scenarios

### Scenario 1: Walk-in Guest Ready to Leave
```javascript
// Walk-in guests don't checkout - they leave freely
if (guestEntry.entry_type === 'Walk_In') {
    showMessage('Walk-in guests may leave anytime. No checkout needed.');
    // Transaction already completed upon payment
}
```

### Scenario 2: Booking Guest 10 Minutes Late (Within Grace Period)
```javascript
const preview = await api.post(`/guest-monitoring/${id}/preview-checkout`);
// Result: overtime.has_overtime = false
// Action: Checkout normally, no overtime dialog needed
```

### Scenario 3: Booking Guest 2 Hours Late, Customer Service Gesture
```javascript
const preview = await api.post(`/guest-monitoring/${id}/preview-checkout`);
// Result: overtime.has_overtime = true, total = 200
// Staff Decision: Skip overtime as goodwill
await api.post(`/guest-monitoring/${id}/checkout`, {
    exit_date: "2025-11-19",
    exit_time: "17:00",
    apply_overtime: false,  // Or omit
    notes: "Overtime waived - first-time customer"
});
```

### Scenario 4: Booking Guest 1 Hour Late, Apply Overtime
```javascript
const preview = await api.post(`/guest-monitoring/${id}/preview-checkout`);
// Result: overtime.has_overtime = true, total = 100
// Staff Decision: Apply overtime
await api.post(`/guest-monitoring/${id}/checkout`, {
    exit_date: "2025-11-19",
    exit_time: "16:00",
    apply_overtime: true,  // Explicitly apply
    notes: "Guest agreed to overtime charges"
});
// Then redirect to payment if balance > 0
```

### Scenario 5: Multiple Facilities, Different Overtime
```javascript
const preview = await api.post(`/guest-monitoring/${id}/preview-checkout`);
// Result: 
// - Cottage: 2 hours × ₱50 = ₱100
// - Kayak: 3 hours × ₱30 = ₱90
// Total overtime: ₱190

// Staff can see breakdown and decide to apply or skip
// If applying, ALL overtime is charged (no partial application)
```

---

## Validation Rules

### CheckoutGuestEntryRequest
```php
[
    'exit_date' => 'required|date|date_format:Y-m-d',
    'exit_time' => 'required|date_format:H:i',
    'apply_overtime' => 'nullable|boolean',  // Optional, defaults to false
    'notes' => 'nullable|string|max:1000',
]
```

### BookingController checkOut
```php
[
    'actual_checkout_datetime' => 'nullable|date',
    'apply_overtime' => 'nullable|boolean',  // Optional, defaults to false
    'additional_charges' => 'nullable|numeric|min:0',
    'notes' => 'nullable|string',
]
```

---

## Configuration

The system still uses the same configuration in `config/billing.php`:

```php
'overtime' => [
    'grace_period_minutes' => 15,        // Grace before overtime starts
    'eligible_for_discounts' => false,   // Discounts on overtime
    'calculation_method' => 'hourly',    // How to calculate hours
    'auto_calculate' => true,            // Enables preview feature
]
```

**Note:** `auto_calculate` enables the overtime calculation engine. The actual application is controlled by the `apply_overtime` request parameter.

---

## Audit Trail

### What Gets Logged

**When overtime is skipped:**
- No BillingExtension record created
- Can track via checkout notes: "Overtime waived - reason"
- No balance increase

**When overtime is applied:**
- BillingExtension records created with `is_overtime = true`
- Facility, rate, discount tracked
- Time tracking: facility_start_datetime, facility_end_datetime
- Added_by: staff who applied the charge
- Guest must pay before checkout completes

---

## Testing Checklist

### Walk-in Tests
- [ ] Walk-in checkout endpoint returns 403 error
- [ ] Walk-in preview checkout endpoint returns 403 error
- [ ] Walk-in billing closes immediately upon full payment
- [ ] Walk-in billing status becomes "completed" after payment
- [ ] Walk-in guests can leave without checkout
- [ ] Frontend hides checkout button for walk-ins

### Booking Tests
- [ ] Preview shows overtime when guest exceeds time + grace period
- [ ] Preview shows no overtime when within grace period
- [ ] Checkout without `apply_overtime` does NOT charge overtime
- [ ] Checkout with `apply_overtime: false` does NOT charge overtime
- [ ] Checkout with `apply_overtime: true` DOES charge overtime
- [ ] Multiple facilities calculate separate overtime amounts
- [ ] Payment required if overtime applied and balance > 0
- [ ] Can checkout without payment if overtime not applied
- [ ] Overtime details appear in response when applied
- [ ] Billing extensions created only when overtime applied
- [ ] Booking status updated to "Checked_Out" after checkout

---

## Quick Reference

### Enable Overtime Feature
```bash
OVERTIME_AUTO_CALCULATE=true
```

### Preview Overtime (Always)
```javascript
POST /guest-monitoring/{id}/preview-checkout
POST /bookings/{id}/preview-check-out
```

### Apply Overtime (Opt-in)
```javascript
{
  "apply_overtime": true  // Required to actually charge
}
```

### Skip Overtime (Default)
```javascript
{
  "apply_overtime": false  // Or omit entirely
}
```

---

**Status:** ✅ Implemented and Ready
**Version:** 2.0.0 (Opt-in)
**Last Updated:** 2025-11-19
