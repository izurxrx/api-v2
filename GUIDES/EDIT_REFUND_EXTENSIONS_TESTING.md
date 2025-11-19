# Edit, Refund, and Extensions Features - Testing Guide

## ✅ Implementation Complete

All three features have been successfully implemented:

### 1. **EDIT Booking** (Pending/Confirmed only)
- ✅ Validation: Rejects Checked_In and Checked_Out bookings
- ✅ Full recalculation: Entrance fees, facilities, services, discounts
- ✅ Automatic billing update: Adjusts balance based on price difference
- ✅ Preserves payment history: Only updates balance, not payment records

### 2. **REFUND Booking** (Manager only)
- ✅ Downpayment policy: Non-refundable by default
- ✅ Manager override: Can refund downpayment with explicit flag
- ✅ Automatic cancellation: Sets booking status to Cancelled
- ✅ Audit trail: Records refund amount, reason, and manager who processed it

### 3. **EXTENSIONS** (Mid-stay charges)
- ✅ Four types: facility, guest, damage, service
- ✅ Immediate payment option: Can collect payment when adding extension
- ✅ Automatic billing update: Adds to total and balance
- ✅ Metadata storage: Flexible JSON field for additional details

---

## Database Changes

### New Table: `billing_extensions`
```sql
CREATE TABLE billing_extensions (
    id BIGINT PRIMARY KEY,
    billing_id BIGINT NOT NULL,
    extension_type ENUM('facility', 'guest', 'damage', 'service'),
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    quantity INT DEFAULT 1,
    total_amount DECIMAL(10,2) NOT NULL,
    metadata JSON NULL,
    added_by BIGINT NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (billing_id) REFERENCES billings(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by) REFERENCES users(id)
);
```

### Updated Table: `billings`
Added refund tracking columns:
- `refund_reason` (VARCHAR, nullable)
- `refunded_by` (BIGINT, nullable, FK to users)
- `refunded_at` (TIMESTAMP, nullable)

---

## API Endpoints

### 1. Edit Booking
**Endpoint:** `PUT /api/booking/{id}`

**Request:**
```json
{
  "booking_type": "Package",
  "number_of_guests": 5,
  "check_in_date": "2025-11-20",
  "check_out_date": "2025-11-21",
  "entrance_rate_id": 2,
  "facilities": [
    {
      "facility_id": 1,
      "rate_id": 3,
      "quantity": 2,
      "rate_amount": 1500.00
    }
  ],
  "discount_mode": "Seasonal",
  "discount_id": 1,
  "manual_discount_amount": 0,
  "special_requests": "Updated special requests"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Booking updated successfully",
  "data": {
    "id": 123,
    "booking_number": "BK-20251117-0001",
    "booking_status": "Confirmed",
    "total_amount": 8500.00
  },
  "changes": {
    "old_total": 7500.00,
    "new_total": 8500.00,
    "difference": 1000.00,
    "new_balance": 4000.00
  }
}
```

**Validation:**
- ❌ Cannot edit if status is `Checked_In` or `Checked_Out`
- ✅ Can edit `Pending` or `Confirmed` bookings
- ✅ All fields can be changed (guests, dates, facilities, discounts)

**Payment Handling:**
- If total increases: Adds difference to balance
- If total decreases: Reduces balance (can result in overpayment - manager decides)

---

### 2. Cancel and Refund Booking
**Endpoint:** `POST /api/booking/{id}/cancel-refund`

**Request:**
```json
{
  "refund_amount": 2500.00,
  "refund_reason": "Customer requested cancellation due to emergency",
  "override_downpayment_policy": false
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Booking cancelled and refunded successfully",
  "data": {
    "booking": {
      "id": 123,
      "booking_status": "Cancelled",
      "cancelled_at": "2025-11-17 21:45:00"
    },
    "refund": {
      "amount": 2500.00,
      "total_paid": 5000.00,
      "downpayment_policy_overridden": false,
      "non_refundable_amount": 2500.00
    }
  }
}
```

**Downpayment Policy:**
- Default: Downpayment is **non-refundable**
- Calculation: `max_refund = total_paid - downpayment_paid`
- Override: Set `override_downpayment_policy: true` to refund full amount

**Example Scenarios:**

**Scenario A: Normal Refund (Downpayment Non-Refundable)**
```
Total: ₱10,000
Downpayment: ₱3,000 (paid)
Balance: ₱7,000 (paid)
--------------------
Total Paid: ₱10,000
Max Refund: ₱7,000 (only balance portion)
Non-Refundable: ₱3,000
```

**Scenario B: Manager Override**
```json
{
  "refund_amount": 10000.00,
  "refund_reason": "Special case - Manager approval",
  "override_downpayment_policy": true
}
```
Result: Full ₱10,000 refunded

**Validation:**
- ❌ Cannot refund `Checked_Out` bookings
- ✅ Can refund `Pending`, `Confirmed`, or `Checked_In` bookings
- ✅ Refund amount cannot exceed `total_paid`
- ✅ Without override: Refund amount capped at `total_paid - downpayment_paid`

---

### 3. Add Extension to Billing
**Endpoint:** `POST /api/billings/{id}/add-extension`

**Request Examples:**

**Example 1: Add Extra Facility (with immediate payment)**
```json
{
  "extension_type": "facility",
  "description": "Additional cottage for Day 2",
  "amount": 1500.00,
  "quantity": 1,
  "metadata": {
    "facility_id": 5,
    "facility_name": "Cottage #12",
    "date_added": "2025-11-18"
  },
  "payment_required": true,
  "payment_amount": 1500.00,
  "payment_method": "Cash"
}
```

**Example 2: Add Extra Guests (payment later)**
```json
{
  "extension_type": "guest",
  "description": "2 additional guests (overnight)",
  "amount": 350.00,
  "quantity": 2,
  "metadata": {
    "guest_names": ["John Doe", "Jane Smith"],
    "rate_type": "overnight"
  },
  "payment_required": false
}
```

**Example 3: Damage Charge**
```json
{
  "extension_type": "damage",
  "description": "Broken cottage window",
  "amount": 2500.00,
  "quantity": 1,
  "metadata": {
    "location": "Cottage #8",
    "damage_report_id": "DMG-2025-001",
    "assessed_by": "John Manager"
  },
  "payment_required": true,
  "payment_amount": 2500.00,
  "payment_method": "Cash"
}
```

**Example 4: Third-Party Service**
```json
{
  "extension_type": "service",
  "description": "Videography service (3 hours)",
  "amount": 800.00,
  "quantity": 3,
  "metadata": {
    "service_provider": "Pro Video Services",
    "hours": 3,
    "date": "2025-11-18"
  },
  "payment_required": true,
  "payment_amount": 2400.00,
  "payment_method": "GCash"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Extension added successfully",
  "data": {
    "extension": {
      "id": 45,
      "billing_id": 67,
      "extension_type": "facility",
      "description": "Additional cottage for Day 2",
      "amount": 1500.00,
      "quantity": 1,
      "total_amount": 1500.00,
      "added_by": 2,
      "created_at": "2025-11-17 21:50:00"
    },
    "billing": {
      "id": 67,
      "total_amount": 12000.00,
      "balance": 0.00,
      "payment_status": "paid"
    },
    "payment": {
      "id": 89,
      "amount": 1500.00,
      "payment_method": "Cash",
      "transaction_reference": "PAY-20251117-0089"
    }
  }
}
```

**Extension Types:**

1. **facility** - Additional facilities (cottages, equipment)
   - Use metadata to store facility_id, dates
   
2. **guest** - Extra guests beyond original count
   - Use metadata for guest names, rate type
   
3. **damage** - Damages or breakages
   - Use metadata for damage report, location, photos
   
4. **service** - Third-party services (videography, catering, etc.)
   - Use metadata for provider details, hours, specifications

**Validation:**
- ❌ Cannot add extensions to `completed`, `cancelled`, or `refunded` billings
- ✅ Can add to `pending`, `confirmed`, or `active` billings
- ✅ Amount must be positive
- ✅ Quantity must be at least 1

---

## Code Files Modified

### Controllers
- ✅ `app/Http/Controllers/Api/BookingController.php`
  - Updated `update()` method with complete implementation
  - Added `cancelRefund()` method

- ✅ `app/Http/Controllers/Api/BillingController.php`
  - Added `addExtension()` method

### Models
- ✅ `app/Models/BillingExtension.php` (NEW)
  - Complete model with relationships

- ✅ `app/Models/Billing.php`
  - Added `extensions()` relationship
  - Added `refundedBy()` relationship
  - Added fillable fields: `refund_reason`, `refunded_by`, `refunded_at`
  - Added cast: `refunded_at` as datetime

### Routes
- ✅ `routes/api.php`
  - `POST /api/booking/{id}/cancel-refund` → BookingController@cancelRefund
  - `POST /api/billings/{id}/add-extension` → BillingController@addExtension

### Migrations
- ✅ `2025_11_17_213450_create_billing_extensions_table.php`
- ✅ `2025_11_17_214422_add_refund_fields_to_billings_table.php`

---

## Testing Checklist

### Edit Booking Tests

- [ ] **Test 1:** Edit Pending booking (should succeed)
  - Create new booking
  - Edit before any payment
  - Verify billing recalculated

- [ ] **Test 2:** Edit Confirmed booking (should succeed)
  - Create booking and pay downpayment
  - Edit booking details
  - Verify balance adjusted correctly

- [ ] **Test 3:** Edit Checked_In booking (should fail)
  - Create booking, pay, and check-in
  - Attempt to edit
  - Expect: 422 error "Cannot edit bookings that are already checked-in"

- [ ] **Test 4:** Edit Checked_Out booking (should fail)
  - Create complete booking cycle
  - Attempt to edit
  - Expect: 422 error

- [ ] **Test 5:** Edit increases total
  - Original: ₱5,000 (paid ₱3,000, balance ₱2,000)
  - Edit to: ₱7,000
  - Expected: New balance = ₱4,000

- [ ] **Test 6:** Edit decreases total
  - Original: ₱5,000 (paid ₱3,000, balance ₱2,000)
  - Edit to: ₱4,000
  - Expected: New balance = ₱1,000 (or negative if overpaid)

### Cancel/Refund Tests

- [ ] **Test 7:** Refund with downpayment policy
  - Booking: ₱10,000
  - Paid: ₱10,000 (₱3,000 downpayment + ₱7,000 balance)
  - Refund: ₱10,000
  - Expected: Only ₱7,000 refunded (downpayment non-refundable)

- [ ] **Test 8:** Refund with manager override
  - Same scenario as Test 7
  - Set `override_downpayment_policy: true`
  - Expected: Full ₱10,000 refunded

- [ ] **Test 9:** Refund Pending booking
  - Create booking, pay downpayment only
  - Request refund
  - Expected: Success (no refund since downpayment non-refundable)

- [ ] **Test 10:** Refund Checked_Out booking (should fail)
  - Complete entire cycle
  - Attempt refund
  - Expect: 422 error "Cannot refund bookings that are already checked-out"

- [ ] **Test 11:** Verify cancellation tracking
  - Perform refund
  - Check booking: `booking_status = 'Cancelled'`
  - Check billing: `billing_status = 'refunded'`
  - Verify: `refunded_by`, `refunded_at` populated

### Extensions Tests

- [ ] **Test 12:** Add facility extension (with payment)
  - Add cottage extension
  - Include immediate payment
  - Verify billing total increased
  - Verify payment recorded

- [ ] **Test 13:** Add guest extension (no payment)
  - Add 2 extra guests
  - Don't include payment
  - Verify balance increased

- [ ] **Test 14:** Add damage charge
  - Add broken item
  - Collect payment immediately
  - Verify extension type = 'damage'

- [ ] **Test 15:** Add service extension
  - Add videography service
  - Verify metadata stored correctly
  - Verify quantity * amount = total_amount

- [ ] **Test 16:** Cannot add to completed billing (should fail)
  - Complete booking and checkout
  - Attempt to add extension
  - Expect: 422 error

- [ ] **Test 17:** Multiple extensions
  - Add 3 different extensions
  - Verify billing total accumulates
  - Verify all extensions listed in billing.extensions relationship

---

## Business Rules Summary

### EDIT Feature
✅ **When Allowed:**
- Pending bookings (not yet confirmed)
- Confirmed bookings (before check-in)

❌ **When Rejected:**
- Checked_In bookings (guest is already inside)
- Checked_Out bookings (transaction complete)

💰 **Payment Handling:**
- Increase: Add difference to balance
- Decrease: Reduce balance (may result in overpayment - manager discretion)

---

### REFUND Feature
🚫 **Default Policy:**
- Downpayment is **non-refundable**
- Only balance portion can be refunded

🔓 **Manager Override:**
- Can refund full amount including downpayment
- Requires explicit flag: `override_downpayment_policy: true`

📋 **Audit Trail:**
- Records refund amount
- Stores refund reason
- Tracks which manager processed it
- Timestamps the refund

---

### EXTENSIONS Feature
🏷️ **Extension Types:**
1. **facility** - Extra cottages, equipment
2. **guest** - Additional guests
3. **damage** - Breakages, damages
4. **service** - Third-party services

💵 **Payment Options:**
- Immediate: Collect payment when adding extension
- Later: Add to balance, collect at checkout

📊 **Automatic Updates:**
- Billing total increases
- Balance increases (if no immediate payment)
- Payment status recalculated

---

## Next Steps

1. **Frontend Implementation**
   - Create Edit Booking dialog
   - Create Cancel/Refund dialog with policy explanation
   - Create Add Extension dialog with type selector

2. **Permission Setup**
   - Ensure only Managers can refund
   - Verify Staff can add extensions
   - Test permission middleware

3. **User Training**
   - Document when Edit is allowed
   - Explain downpayment policy
   - Show how to use extensions

4. **Reporting**
   - Add refunds to financial reports
   - Track extension revenue separately
   - Show edit history in audit logs

---

## Contact

For questions about implementation, contact the development team.

**Features Status:** ✅ ALL IMPLEMENTED AND READY FOR TESTING
