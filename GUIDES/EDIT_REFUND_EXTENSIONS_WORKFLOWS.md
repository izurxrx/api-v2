# Edit, Refund, and Extensions - Feature Workflows

## 1. EDIT Booking Workflow

```
┌─────────────────────────────────────────────────────────────────┐
│                    EDIT BOOKING WORKFLOW                        │
└─────────────────────────────────────────────────────────────────┘

User wants to edit booking
         ↓
    Check Status
         ↓
    ┌────────────────┐
    │  Is Pending or │ ───NO──→ ❌ Reject (422)
    │  Confirmed?    │           "Cannot edit checked-in/out bookings"
    └────────────────┘
         ↓ YES
    Recalculate All:
    • Entrance fees
    • Facilities
    • Services
    • Discounts
         ↓
    Compare Old vs New Total
         ↓
    ┌───────────────────────────┐
    │ Old: ₱5,000               │
    │ New: ₱7,000               │
    │ Difference: +₱2,000       │
    └───────────────────────────┘
         ↓
    Update Billing:
    • Total = ₱7,000
    • Balance = Old Balance + ₱2,000
         ↓
    ✅ Success
    Return updated booking + changes summary
```

**Key Features:**
- ✅ Full recalculation of all charges
- ✅ Automatic balance adjustment
- ✅ Preserves payment history
- ❌ Rejects if guest already checked in/out

---

## 2. REFUND Booking Workflow

```
┌─────────────────────────────────────────────────────────────────┐
│                  CANCEL & REFUND WORKFLOW                       │
└─────────────────────────────────────────────────────────────────┘

Manager initiates refund
         ↓
    Check Status
         ↓
    ┌────────────────┐
    │ Is Checked_Out?│ ───YES──→ ❌ Reject (422)
    └────────────────┘           "Cannot refund checked-out bookings"
         ↓ NO
    Calculate Refund Limit
         ↓
    ┌─────────────────────────────────────────────────┐
    │ Total Paid: ₱10,000                            │
    │   • Downpayment: ₱3,000                        │
    │   • Balance: ₱7,000                            │
    └─────────────────────────────────────────────────┘
         ↓
    ┌────────────────────────────────────┐
    │ Override Downpayment Policy?       │
    └────────────────────────────────────┘
         ↓                    ↓
       NO                   YES
         ↓                    ↓
    Max Refund:          Max Refund:
    ₱7,000               ₱10,000
    (balance only)       (full amount)
         ↓                    ↓
         └────────┬───────────┘
                  ↓
         Apply Min(requested, max)
                  ↓
         Update Billing:
         • status = 'refunded'
         • refund_amount = X
         • refunded_by = manager_id
         • refunded_at = now()
                  ↓
         Update Booking:
         • status = 'Cancelled'
         • cancellation_reason = "..."
                  ↓
         ✅ Success
         Return refund details
```

**Downpayment Policy Matrix:**

| Scenario | Total Paid | Downpayment | Override | Refundable | Non-Refundable |
|----------|-----------|-------------|----------|------------|----------------|
| Normal   | ₱10,000   | ₱3,000      | No       | ₱7,000     | ₱3,000         |
| Override | ₱10,000   | ₱3,000      | Yes      | ₱10,000    | ₱0             |
| Partial  | ₱5,000    | ₱3,000      | No       | ₱2,000     | ₱3,000         |

---

## 3. EXTENSIONS Workflow

```
┌─────────────────────────────────────────────────────────────────┐
│                   ADD EXTENSION WORKFLOW                        │
└─────────────────────────────────────────────────────────────────┘

Staff/Manager needs to add charge
         ↓
    Check Billing Status
         ↓
    ┌──────────────────────┐
    │ Is completed,        │ ───YES──→ ❌ Reject (422)
    │ cancelled, refunded? │           "Cannot add to completed billing"
    └──────────────────────┘
         ↓ NO
    Select Extension Type
         ↓
    ┌───────────────────────────────────────────┐
    │  • facility  (extra cottage)              │
    │  • guest     (additional guests)          │
    │  • damage    (broken items)               │
    │  • service   (videography, etc.)          │
    └───────────────────────────────────────────┘
         ↓
    Enter Details:
    • Description
    • Amount per unit
    • Quantity
    • Metadata (optional)
         ↓
    Calculate Total:
    Total = Amount × Quantity
         ↓
    Payment Option?
         ↓
    ┌─────────────────────────────┐
    │  Collect Now?               │
    └─────────────────────────────┘
         ↓              ↓
       YES            NO
         ↓              ↓
    Record Payment   Add to Balance
    immediately      (collect later)
         ↓              ↓
         └─────┬────────┘
               ↓
    Create Extension Record:
    • billing_id
    • extension_type
    • description
    • amount, quantity, total
    • metadata
    • added_by (current user)
               ↓
    Update Billing:
    • total_amount += extension_total
    • balance += extension_total (if not paid)
               ↓
    ✅ Success
    Return extension + updated billing
```

**Extension Types Details:**

### 🏖️ Facility Extension
```json
{
  "extension_type": "facility",
  "description": "Extra cottage for Day 2",
  "amount": 1500.00,
  "quantity": 1,
  "metadata": {
    "facility_id": 12,
    "facility_name": "Cottage #12",
    "date_added": "2025-11-18"
  }
}
```
**Use Case:** Guest wants additional facility during their stay

---

### 👥 Guest Extension
```json
{
  "extension_type": "guest",
  "description": "2 additional overnight guests",
  "amount": 350.00,
  "quantity": 2,
  "metadata": {
    "guest_names": ["John Doe", "Jane Smith"],
    "rate_type": "overnight",
    "added_date": "2025-11-18"
  }
}
```
**Use Case:** Extra guests arrived unexpectedly

---

### 💔 Damage Extension
```json
{
  "extension_type": "damage",
  "description": "Broken cottage window",
  "amount": 2500.00,
  "quantity": 1,
  "metadata": {
    "location": "Cottage #8",
    "damage_report_id": "DMG-2025-001",
    "assessed_by": "John Manager",
    "photos": ["url1", "url2"]
  }
}
```
**Use Case:** Property damage needs to be charged

---

### 🎥 Service Extension
```json
{
  "extension_type": "service",
  "description": "Videography service",
  "amount": 800.00,
  "quantity": 3,
  "metadata": {
    "service_provider": "Pro Video Services",
    "hours": 3,
    "date": "2025-11-18",
    "contact": "0917-123-4567"
  }
}
```
**Use Case:** Guest requested third-party service

---

## Feature Comparison

| Feature | When Allowed | Payment Impact | Status Change | Audit Trail |
|---------|--------------|----------------|---------------|-------------|
| **EDIT** | Pending, Confirmed | Recalculates balance | No | Yes (updated_by) |
| **REFUND** | Pending, Confirmed, Checked_In | Reverses payment | Yes (→Cancelled) | Yes (refunded_by, reason) |
| **EXTENSION** | Pending, Confirmed, Active | Adds to balance | No | Yes (added_by) |

---

## Validation Rules Summary

### ✅ EDIT Booking
```
IF booking_status IN ('Pending', 'Confirmed') THEN
    ALLOW edit
    RECALCULATE billing
    ADJUST balance
ELSE
    REJECT "Cannot edit checked-in/out bookings"
```

### ✅ REFUND Booking
```
IF booking_status == 'Checked_Out' THEN
    REJECT "Cannot refund checked-out bookings"
ELSE
    IF override_downpayment_policy == true THEN
        max_refund = total_paid
    ELSE
        max_refund = total_paid - downpayment_paid
    
    refund_amount = MIN(requested_amount, max_refund)
    
    UPDATE billing SET status = 'refunded'
    UPDATE booking SET status = 'Cancelled'
```

### ✅ EXTENSION
```
IF billing_status IN ('completed', 'cancelled', 'refunded') THEN
    REJECT "Cannot add extensions to completed billing"
ELSE
    total_extension = amount * quantity
    
    CREATE billing_extension
    
    UPDATE billing SET
        total_amount += total_extension,
        balance += total_extension
    
    IF payment_required THEN
        RECORD payment
    END
```

---

## Response Formats

### Edit Response
```json
{
  "status": "success",
  "message": "Booking updated successfully",
  "data": { /* booking details */ },
  "changes": {
    "old_total": 5000.00,
    "new_total": 7000.00,
    "difference": 2000.00,
    "new_balance": 4000.00
  }
}
```

### Refund Response
```json
{
  "status": "success",
  "message": "Booking cancelled and refunded successfully",
  "data": {
    "booking": { /* booking details */ },
    "refund": {
      "amount": 7000.00,
      "total_paid": 10000.00,
      "downpayment_policy_overridden": false,
      "non_refundable_amount": 3000.00
    }
  }
}
```

### Extension Response
```json
{
  "status": "success",
  "message": "Extension added successfully",
  "data": {
    "extension": { /* extension details */ },
    "billing": { /* updated billing */ },
    "payment": { /* payment if immediate */ }
  }
}
```

---

## Error Responses

### Edit Rejected (Checked_In)
```json
{
  "status": "error",
  "message": "Cannot edit bookings that are already checked-in or checked-out"
}
```
**HTTP Status:** 422 Unprocessable Entity

### Refund Rejected (Checked_Out)
```json
{
  "status": "error",
  "message": "Cannot refund bookings that are already checked-out"
}
```
**HTTP Status:** 422 Unprocessable Entity

### Extension Rejected (Completed)
```json
{
  "status": "error",
  "message": "Cannot add extensions to completed billings"
}
```
**HTTP Status:** 422 Unprocessable Entity

---

## Implementation Status

✅ All features implemented and tested
✅ Database migrations run successfully
✅ Routes registered and accessible
✅ Validation rules in place
✅ Audit trails configured
✅ Documentation complete

**Ready for:** Frontend integration and user testing
