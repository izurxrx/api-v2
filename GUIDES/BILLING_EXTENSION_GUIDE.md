# Billing Extension Guide - How Extensions Work

## 🎯 Extension Philosophy

**Extensions = Add MORE stuff to existing booking/entry**

Extensions allow you to add facilities, guests, or services to an **already active** booking or walk-in entry **without changing the original dates or guest information**.

---

## ✅ What Extensions CAN Do

| Action | Booking | Walk-in Entry | Example |
|--------|---------|---------------|---------|
| Add facilities | ✅ Yes | ✅ Yes | Rent jetski mid-stay |
| Add more guests | ✅ Yes | ✅ Yes | Friends arrived unexpectedly |
| Add services | ✅ Yes | ✅ Yes | Order food, pay damage fee |
| Apply discounts | ✅ Yes | ✅ Yes | Discount on new items |
| Record payment | ✅ Yes | ✅ Yes | Pay for extensions immediately |

---

## ❌ What Extensions CANNOT Do

| Action | Why Not | Alternative |
|--------|---------|-------------|
| Change guest name | Already set in booking | Use booking update endpoint |
| Change contact info | Already set in booking | Use booking update endpoint |
| Change check-in date | Fixed when created | N/A - can't change past |
| Change check-out date | Use overtime instead | Overtime charges at checkout |
| Remove facilities | That's a refund | Use refund endpoint |
| Change booking type | Core booking property | Create new booking |

---

## 🔧 How Extensions Work

### Flow Comparison

#### Booking Creation:
```
1. Guest info (name, contact) ✅
2. Dates (check-in, check-out) ✅
3. Facilities + rates ✅
4. Guest count + entrance rate ✅
5. Discounts ✅
6. Services ✅
7. Calculate total ✅
8. Create billing ✅
9. Optional downpayment ✅
```

#### Extension (Option A):
```
1. ❌ NO guest info (already exists)
2. ❌ NO dates (already set)
3. ✅ Add facilities + rates
4. ✅ Add more guests
5. ✅ Apply discounts
6. ✅ Add services
7. ✅ Calculate extension total
8. ✅ Update billing total
9. ✅ Optional payment
```

---

## 📋 Extension Types

### 1. Facility Extension
**Use case:** Guest wants to rent additional facilities

**Example:**
```json
POST /api/billings/{billing_id}/extensions

{
  "facilities": [
    {
      "facility_id": 5,
      "rate_id": 15,
      "quantity": 2,
      "hours": 3
    }
  ]
}
```

**What happens:**
- Adds jetski rental for 3 hours
- Calculates: rate × hours × quantity
- Adds to billing total
- Guest pays at checkout

---

### 2. Guest Extension
**Use case:** More people arrived

**Example:**
```json
POST /api/billings/{billing_id}/extensions

{
  "guest_charges": [
    {
      "guest_type": "Adult",
      "count": 2,
      "rate_per_guest": 150,
      "discount_id": 3
    }
  ]
}
```

**What happens:**
- Adds 2 adult guests
- Rate: ₱150 per guest
- Applies discount if provided
- Updates billing total

---

### 3. Service Extension
**Use case:** Order food, pay for damage, etc.

**Example:**
```json
POST /api/billings/{billing_id}/extensions

{
  "third_party_services": [
    {
      "service_name": "Catering - Lunch Set",
      "amount": 2500
    }
  ]
}
```

**What happens:**
- Adds service charge
- Fixed amount (₱2,500)
- Updates billing total

---

### 4. Mixed Extension
**Use case:** Add multiple things at once

**Example:**
```json
POST /api/billings/{billing_id}/extensions

{
  "facilities": [
    {
      "facility_id": 5,
      "rate_id": 15,
      "quantity": 1,
      "hours": 2
    }
  ],
  "guest_charges": [
    {
      "guest_type": "Child",
      "count": 3,
      "rate_per_guest": 100
    }
  ],
  "third_party_services": [
    {
      "service_name": "Damage - Broken chair",
      "amount": 500
    }
  ],
  "manual_discount_amount": 200,
  "payment_required": true,
  "payment_amount": 1000,
  "payment_method": "cash"
}
```

**What happens:**
- Adds jetski (2 hours)
- Adds 3 children
- Adds damage fee
- Applies ₱200 discount
- Records ₱1,000 payment
- Updates billing

---

## 🕒 Handling Time Extensions

### Question: What if guest wants to stay longer?

**Answer:** Use the existing **Overtime System** - NOT extensions!

### For Bookings (Pre-booked stays):

#### Scenario 1: Late Checkout (Few Hours)
```
Booking: Nov 24-26, checkout at 12:00 PM
Guest: Wants to checkout at 3:00 PM (3 hours late)

Solution: Use Overtime System ✅
```

**How it works:**
1. Guest checks out at 3:00 PM
2. System calculates overtime automatically
3. Charges based on facility rates + grace period
4. Added to final bill

**Already implemented in:**
- `BookingController::checkOut()`
- `OvertimeCalculationService`

---

#### Scenario 2: Extend by Full Days
```
Booking: Nov 24-26 (2 nights)
Guest: Wants to stay until Nov 27 (1 more night)

Solution: Add facility extension OR create new booking
```

**Option A: Manual Extension** ✅
```json
POST /api/billings/{billing_id}/extensions

{
  "facilities": [
    {
      "facility_id": 1,
      "rate_id": 10,
      "quantity": 1,
      "hours": 24
    }
  ],
  "description": "Extended stay - 1 additional night"
}
```

**Option B: New Booking**
- Create separate booking for Nov 26-27
- Cleaner separation
- Different booking reference

---

### For Walk-in Entries (No pre-set checkout):

#### Walk-ins are flexible!
```
Walk-in: Nov 24, 10:00 AM
No fixed checkout time

Guest leaves: Nov 24, 6:00 PM
→ Checkout → Calculate time-based charges
```

**Process:**
1. Check-in (no checkout time set)
2. Add extensions during stay (facilities, guests, services)
3. Checkout when ready → System calculates duration charges
4. Payment includes all extensions

---

## 💰 Payment Flow

### Without Immediate Payment
```json
POST /api/billings/{billing_id}/extensions

{
  "facilities": [...],
  "payment_required": false
}
```

**Result:**
- Extension added
- Billing total updated
- Balance increased
- Guest pays at checkout

---

### With Immediate Payment
```json
POST /api/billings/{billing_id}/extensions

{
  "facilities": [...],
  "payment_required": true,
  "payment_amount": 1500,
  "payment_method": "cash"
}
```

**Result:**
- Extension added
- Billing total updated
- Payment recorded immediately
- Balance reduced by payment amount

---

## 🎨 Extension vs Update vs Overtime

| Scenario | Use | Endpoint |
|----------|-----|----------|
| Add jetski rental | Extension | `POST /billings/{id}/extensions` |
| Add more guests | Extension | `POST /billings/{id}/extensions` |
| Add food service | Extension | `POST /billings/{id}/extensions` |
| Change guest name | Update | `PUT /bookings/{id}` |
| Change contact | Update | `PUT /bookings/{id}` |
| Checkout 3 hrs late | Overtime | `POST /bookings/{id}/checkout` |
| Stay 1 more night | Extension | `POST /billings/{id}/extensions` |

---

## 📊 Real-World Examples

### Example 1: Day Guest Adds Facilities
```
Walk-in Entry:
- Guest: John Doe
- Entry: Nov 24, 10:00 AM
- Swimming only (₱200)

12:00 PM: John wants jetski
→ Add extension:
{
  "facilities": [{
    "facility_id": 5,
    "rate_id": 15,
    "quantity": 1,
    "hours": 2
  }]
}

2:00 PM: John wants lunch
→ Add extension:
{
  "third_party_services": [{
    "service_name": "Lunch",
    "amount": 350
  }]
}

5:00 PM: Checkout
→ Total: ₱200 (swimming) + ₱400 (jetski) + ₱350 (lunch) = ₱950
```

---

### Example 2: Booking Guest Extends Stay
```
Booking:
- Guest: Jane Smith
- Dates: Nov 24-26 (2 nights)
- Cottage #1 (₱3,000/night)
- Total: ₱6,000

Nov 25: Jane wants to stay until Nov 27
→ Add extension:
{
  "facilities": [{
    "facility_id": 1,
    "rate_id": 10,
    "quantity": 1,
    "hours": 24
  }],
  "description": "Extended stay - 1 more night"
}

Nov 27: Checkout
→ Total: ₱6,000 (original) + ₱3,000 (extension) = ₱9,000
```

---

### Example 3: Unexpected Guests Arrive
```
Booking:
- Guest: Carlos Family
- Booked for: 4 people
- Already paid downpayment

Check-in day: 2 more family members arrive
→ Add extension:
{
  "guest_charges": [{
    "guest_type": "Adult",
    "count": 2,
    "rate_per_guest": 150
  }]
}

→ New charges: ₱300
→ Pay at checkout
```

---

## 🔒 Business Rules

### 1. Extensions Only on Active Billings
```
✅ Allowed: pending, confirmed, active billings
❌ Blocked: voided, cancelled, completed billings
```

### 2. Original Info Stays Fixed
```
✅ Original: Check-in date, guest name, contact
❌ Can't change: These are permanent
✅ Can add: New facilities, guests, services
```

### 3. Payment is Flexible
```
✅ Pay now: Immediate payment with extension
✅ Pay later: Add to balance, pay at checkout
✅ Partial: Pay some now, rest later
```

### 4. Discounts Apply to New Items
```
✅ Direct discounts: On guest charges
✅ Seasonal discounts: On total extension
✅ Manual discounts: Staff discretion
```

---

## 🎯 Summary

**Extension Philosophy (Option A):**

✅ **DO:** Add facilities, guests, services
❌ **DON'T:** Change dates, names, core booking info

**For Time Extensions:**
- **Late checkout (hours):** Use overtime system
- **Extra days:** Add facility extension OR new booking
- **Walk-ins:** No fixed end time, pay when leaving

**Why Option A?**
- ✅ Simple and predictable
- ✅ No availability conflicts
- ✅ Clear audit trail
- ✅ Matches existing code
- ✅ Easy for staff to understand

---

**Last Updated:** November 24, 2025
**Status:** ✅ Implemented (Option A)
**Your extension system is ready to use!**
