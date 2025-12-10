# Refund Policy - Downpayment is 100% Non-Refundable

## ✅ Policy Clarification

**Correct Policy:** The entire downpayment (50% of booking total) is **100% non-refundable**.

**Implementation:**
```php
// ✅ POLICY: Downpayment (50% of booking total) is 100% non-refundable
// Only balance payments exceeding the downpayment can be refunded
$maxRefundAmount = $balancePaid; // Only balance payments are refundable
```

**Example:**
- Total booking: ₱10,000
- Downpayment required (50%): ₱5,000
- Balance: ₱5,000
- Customer paid: ₱5,000 (downpayment) + ₱5,000 (balance) = ₱10,000 total

**Calculation:**
- Refundable from downpayment: **₱0** (100% non-refundable)
- Refundable from balance: ₱5,000 (100% refundable)
- **Max refund = ₱5,000** ✅
- **Customer loses ₱5,000** (entire downpayment)

---

## 📊 Refund Examples

### Example 1: Full Payment, Standard Refund

**Scenario:** Customer paid everything, requests refund without override

**Payment:**
- Total booking: ₱10,000
- Downpayment paid: ₱5,000
- Balance paid: ₱5,000
- **Total paid: ₱10,000**

**Refund Calculation:**
- Refundable from downpayment: **₱0** (100% non-refundable)
- Refundable from balance: ₱5,000
- **Max refund: ₱5,000**
- **Non-refundable: ₱5,000** (entire downpayment)

**API Response:**
```json
{
  "status": "success",
  "message": "Booking cancelled and refunded successfully",
  "data": {
    "refund": {
      "refund_amount": 5000,
      "payment_breakdown": {
        "total_paid": 10000,
        "downpayment_paid": 5000,
        "balance_paid": 5000
      },
      "refund_breakdown": {
        "refundable_from_downpayment": 0,
        "non_refundable_from_downpayment": 5000,
        "refundable_from_balance": 5000,
        "max_refundable": 5000
      },
      "policy": {
        "downpayment_policy": "Downpayment (50% of booking total) is 100% non-refundable",
        "downpayment_policy_overridden": false
      },
      "non_refundable_amount": 5000
    }
  }
}
```

---

### Example 2: Only Downpayment Paid

**Scenario:** Customer only paid downpayment, never paid balance

**Payment:**
- Total booking: ₱10,000
- Downpayment paid: ₱5,000
- Balance paid: ₱0
- **Total paid: ₱5,000**

**Refund Calculation:**
- Refundable from downpayment: **₱0** (100% non-refundable)
- Refundable from balance: ₱0
- **Max refund: ₱0**
- **Non-refundable: ₱5,000** (entire downpayment)

---

### Example 3: Manager Override (100% Refund)

**Scenario:** Manager overrides policy to refund everything

**Payment:**
- Total booking: ₱10,000
- Downpayment paid: ₱5,000
- Balance paid: ₱5,000
- **Total paid: ₱10,000**

**Request:**
```json
{
  "refund_amount": 10000,
  "refund_reason": "Emergency cancellation - customer request",
  "override_downpayment_policy": true
}
```

**Refund Calculation:**
- With override: **Max refund = ₱10,000** (100% of total paid)
- **Non-refundable: ₱0**

**API Response:**
```json
{
  "refund": {
    "refund_amount": 10000,
    "payment_breakdown": {
      "total_paid": 10000,
      "downpayment_paid": 5000,
      "balance_paid": 5000
    },
    "refund_breakdown": {
      "refundable_from_downpayment": 0,
      "non_refundable_from_downpayment": 5000,
      "refundable_from_balance": 5000,
      "max_refundable": 10000
    },
    "policy": {
      "downpayment_policy": "Downpayment (50% of booking total) is 100% non-refundable",
      "downpayment_policy_overridden": true
    },
    "non_refundable_amount": 0
  }
}
```

---

### Example 4: Partial Payment

**Scenario:** Customer paid downpayment + partial balance

**Payment:**
- Total booking: ₱10,000
- Downpayment paid: ₱5,000
- Balance paid: ₱2,000
- **Total paid: ₱7,000**

**Refund Calculation:**
- Refundable from downpayment: **₱0** (100% non-refundable)
- Refundable from balance: ₱2,000
- **Max refund: ₱2,000**
- **Non-refundable: ₱5,000** (entire downpayment)

---

## 🧪 Testing the Fix

### Test 1: Standard Refund (No Override)

```bash
POST /api/bookings/{id}/cancel-refund
Authorization: Bearer {manager_token}
Content-Type: application/json

{
  "refund_amount": 10000,
  "refund_reason": "Customer requested cancellation"
}
```

**Expected Result:**
- Refund amount: ₱5,000 (capped at max_refundable = balance only)
- Non-refundable: ₱5,000 (entire downpayment)

---

### Test 2: Manager Override

```bash
POST /api/bookings/{id}/cancel-refund
Authorization: Bearer {manager_token}
Content-Type: application/json

{
  "refund_amount": 10000,
  "refund_reason": "Emergency cancellation - approved by management",
  "override_downpayment_policy": true
}
```

**Expected Result:**
- Refund amount: ₱10,000 (full refund)
- Non-refundable: ₱0

---

### Test 3: Partial Refund Request

```bash
POST /api/bookings/{id}/cancel-refund
Authorization: Bearer {manager_token}
Content-Type: application/json

{
  "refund_amount": 5000,
  "refund_reason": "Partial refund as per customer agreement"
}
```

**Expected Result:**
- Refund amount: ₱5,000 (requested amount equals max refundable = balance only)
- Non-refundable: ₱5,000 (entire downpayment)

---

## 📋 Verification Checklist

After implementing the fix, verify:

- [ ] Customer who paid ₱10,000 total gets max refund of ₱5,000 (without override)
- [ ] Entire downpayment (₱5,000) is correctly marked as 100% non-refundable
- [ ] Balance payments are 100% refundable
- [ ] Manager override allows 100% refund (₱10,000)
- [ ] Refund breakdown shows correct calculations
- [ ] Non-refundable amount is correct in response (₱5,000 without override)
- [ ] Logs show detailed payment and refund breakdown

---

## 📐 Formula Reference

```
STANDARD REFUND (No Override):
─────────────────────────────
Non-Refundable = Downpayment Paid (100%)
Refundable Downpayment = ₱0
Refundable Balance = Balance Paid (100%)
Max Refund = Balance Paid

MANAGER OVERRIDE:
────────────────
Max Refund = Total Paid (100%)
Non-Refundable = 0
```

---

## 🎯 Policy Summary

| Aspect | Standard Refund | Manager Override |
|--------|----------------|------------------|
| Downpayment refundable | 0% (100% non-refundable) | 100% (with override) |
| Balance refundable | 100% ✅ | 100% ✅ |
| Max refund (₱10k total paid) | ₱5,000 | ₱10,000 |
| Customer loses | ₱5,000 (downpayment) | ₱0 |
| Business keeps | ₱5,000 minimum | Optional (manager discretion) |

---

## 💡 Business Logic

**Why is downpayment 100% non-refundable?**
- Protects business from last-minute cancellations
- Covers administrative and opportunity costs
- Reserves the booking slot (preventing other bookings)
- Compensates for potential lost revenue
- Standard practice in hospitality industry

**Why allow manager override?**
- Emergency situations (medical, force majeure)
- Long-term customer relationships
- Special circumstances requiring goodwill
- Manager discretion for case-by-case evaluation
- Maintains customer satisfaction in exceptional cases

---

## 🔍 Database Verification

Check refund records:
```sql
SELECT
    b.id,
    b.booking_reference,
    b.booking_status,
    bil.total_amount,
    bil.downpayment_paid,
    bil.balance_paid,
    bil.downpayment_paid + bil.balance_paid as total_paid,
    bil.refund_amount,
    bil.refund_reason,
    bil.refunded_at
FROM bookings b
JOIN billings bil ON bil.billable_id = b.id AND bil.billable_type = 'App\\Models\\Booking'
WHERE b.booking_status = 'Cancelled'
AND bil.payment_status = 'refunded'
ORDER BY bil.refunded_at DESC;
```

**Verify:**
- refund_amount ≤ balance_paid (standard refund)
- OR refund_amount = total_paid (if override was used)
- downpayment_paid should never be refunded (unless override)

---

**Policy Implemented:** November 24, 2025
**Status:** ✅ Ready for testing
**Policy:** Downpayment (50% of booking total) is 100% non-refundable
