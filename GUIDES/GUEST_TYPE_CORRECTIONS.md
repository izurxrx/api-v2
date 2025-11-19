# Guest Type and Discount Corrections - Backend Update

**Date:** November 18, 2025  
**Status:** ✅ **IMPLEMENTED**

---

## ⚠️ IMPORTANT: Understanding the System

**There are TWO separate concepts - don't mix them up!**

### 1️⃣ Guest Type LABELS (for API requests)
These are simple labels the frontend uses to categorize guests:
- ✅ **"Adult"** - Regular/Adult guests (full price, ages 2-59)
- ✅ **"Senior Citizen"** - Senior citizens (ages 60+, eligible for 20% discount)
- ✅ **"Children below 2 yrs old"** - Infants/toddlers (ages 0-2) - **FREE entry**

### 2️⃣ DISCOUNTS (from `discounts` table - Direct Discounts)
These are actual discount records in your database with IDs:
- ✅ **"Senior Citizen Discount"** (discount_id = 16) - 20% off entrance, applied to "Senior Citizen" guests
- ✅ **"Children Below 2 years old"** (discount_id = 17) - FREE entry discount for infants/toddlers
- ✅ **Regular/Adult (full price)** - `discount_id = null` - No discount, pays full entrance rate

### 3️⃣ Guest Types Table (backend only, ignore)
This is a legacy table with: Adult, Child, Senior, PWD, Infant - **NOT used by the API!**

---

## The Confusion Explained

**Why separate concepts?**

1. **Guest Type Label** = How you COUNT people
   - "I have 3 Adults, 2 Senior Citizens, 1 Child below 2"
   
2. **Discount** = What DISCOUNT applies to their entrance fee
   - "Senior Citizens" get "Senior Citizen Discount" (20% off)
   - "Children below 2" are FREE (no discount, just ₱0)
   - "Adults" who are ages 2-12 can get "Child Discount" (40% off)

**Examples:**
```
1. Guest: "Senior Citizen" (age 65)
   Discount: discount_id = 16 (Senior Citizen Discount, 20% off)
   Result: Pays 80% of entrance rate

2. Guest: "Children below 2 yrs old" (age 1)
   Discount: discount_id = 17 (Children Below 2 years old - FREE)
   Result: FREE entry (₱0)

3. Guest: "Adult" (age 30)
   Discount: discount_id = null (No discount)
   Result: Pays 100% of entrance rate (REGULAR PRICE)
```

---

## Changes Made

### 1. Guest Type Names (Walk-in Guest Entry)

**Valid guest_type_name values:**
- **Adult** - Regular/adult paying guests (ages 2-59, full entrance rate)
- **Senior Citizen** - Senior citizens ages 60+ (eligible for 20% discount)
- **Children below 2 yrs old** - Infants/toddlers ages 0-2 (**FREE** - no entrance fee)

---

### 2. Removed PWD Guest Type

**PWD (Person with Disability)** has been removed from:
- ❌ Booking guest discount validation
- ❌ Guest breakdown validation
- ❌ All calculation logic

**Reason:** PWD discount type doesn't exist in your current database setup.

---

## API Changes

### Walk-in Guest Entry (`POST /api/guest-monitoring`)

**Updated Validation:**
```json
{
  "guest_details": [
    {
      "guest_type_name": "Adult",
      "guest_count": 3,
      "discount_id": null              // ✅ REGULAR PRICE (no discount)
    },
    {
      "guest_type_name": "Senior Citizen",
      "guest_count": 2,
      "discount_id": 16                // ✅ Senior Citizen Discount (20% off)
    },
    {
      "guest_type_name": "Children below 2 yrs old",
      "guest_count": 1,
      "discount_id": 17                // ✅ Children Below 2 years old (FREE)
    }
  ]
}
```

**Important Notes:**
- ✅ **Regular/Adult (full price)**: `discount_id = null` - Pays 100% of entrance rate
- ✅ **Senior Citizen**: `discount_id = 16` - Gets 20% off (Senior Citizen Discount from discounts table)
- ✅ **Children below 2 yrs old**: `discount_id = 17` - FREE entry using discount record from discounts table
- 📝 The "Children Below 2 years old" discount is auto-applied by selecting the guest type

---

### Bookings (`POST /api/bookings`)

**Updated Guest Breakdown:**
```json
{
  "guest_breakdown": {
    "adult": 5,
    "senior": 2,
    "child": 3,
    "infant": 1     // ✅ Added (replaces pwd)
  },
  "number_of_guests": 11
}
```

**Updated Guest Discounts:**
```json
{
  "guest_discounts": [
    {
      "guest_type": "senior",   // ✅ Valid
      "count": 2,
      "discount_id": 1
    },
    {
      "guest_type": "child",    // ✅ Valid
      "count": 3,
      "discount_id": 2
    }
    // ❌ "pwd" is NO LONGER valid
  ]
}
```

---

## Frontend Changes Required

### 1. Walk-in Guest Entry Form

**Update Guest Type Dropdown:**
```typescript
// ✅ Use these exact values (match API validation):
guestTypes = [
  { 
    value: 'Adult', 
    label: 'Adult (Regular)',
    hasDiscount: false,
    description: 'Regular guests, ages 2-59, full entrance rate'
  },
  { 
    value: 'Senior Citizen', 
    label: 'Senior Citizen',
    hasDiscount: true,
    discountType: 'Senior Citizen Discount',
    description: 'Ages 60+, eligible for 20% discount'
  },
  { 
    value: 'Children below 2 yrs old', 
    label: 'Infant/Toddler (FREE)',
    hasDiscount: false,
    isFree: true,
    description: 'Ages 0-2, FREE entry (no entrance fee)'
  }
];
```

**Auto-Discount Handling:**
```typescript
// When "Senior Citizen" is selected:
// - Show/auto-select "Senior Citizen Discount" (20% off)
// - Allow override to null if no discount wanted

// When "Children below 2 yrs old" is selected:
// - Hide discount dropdown completely
// - Set discount_id to null (force)
// - Show "FREE" badge
// - No entrance fee charged

// When "Adult" is selected:
// - Check if "Child Discount" is available for ages 2-12
// - If child age 2-12, allow selecting "Child Discount" (40% off)
// - Otherwise, no discount available

if (guestType === 'Children below 2 yrs old') {
  discountId = null;  // Force null
  showDiscountDropdown = false;
  showFreeBadge = true;
  entranceFee = 0;  // FREE
}
```

---

### 2. Booking Form

**Update Guest Breakdown:**
```typescript
// ❌ OLD (Remove):
guestBreakdown = {
  adult: 0,
  senior: 0,
  pwd: 0,      // ❌ Remove
  child: 0
};

// ✅ NEW (Use):
guestBreakdown = {
  adult: 0,
  senior: 0,
  child: 0,
  infant: 0    // ✅ Add
};
```

**Update Guest Discount Types:**
```typescript
// ❌ OLD (Remove):
validGuestTypes = ['senior', 'pwd', 'child'];

// ✅ NEW (Use):
validGuestTypes = ['senior', 'child'];  // No PWD
```

---

## Validation Errors

### New Error for Infant Discount:
```json
{
  "status": "error",
  "errors": {
    "guest_details.0.discount_id": [
      "Infants are free and cannot have discounts applied."
    ]
  }
}
```

### Invalid Guest Type:
```json
{
  "status": "error",
  "errors": {
    "guest_details.0.guest_type_name": [
      "The selected guest type is invalid."
    ]
  }
}
```

**Valid Values:**
- Walk-in: `Adult`, `Child`, `Senior`, `Infant`
- Booking guest_discounts: `senior`, `child` (lowercase)

---

## Database Structure

### Guest Types Table (Reference)
```
+----+--------+------------------------------------------+
| id | name   | description                              |
+----+--------+------------------------------------------+
| 1  | Adult  | Regular adult guest (18-59 years old)    |
| 2  | Child  | Child guest (12 years old and below)     |
| 3  | Senior | Senior citizen (60 years old and above)  |
| 4  | Infant | Infant (2 years old and below) - Free    |
+----+--------+------------------------------------------+
```

**Note:** PWD type may exist in database from seeder but is not used in API validation.

---

## Testing Scenarios

### Test 1: Walk-in with Infant (Free)
```json
{
  "guest_details": [
    { "guest_type_name": "Adult", "guest_count": 2, "discount_id": null },
    { "guest_type_name": "Infant", "guest_count": 1, "discount_id": null }
  ]
}
// ✅ Expected: Infant counted but FREE (no entrance fee)
```

### Test 2: Infant with Discount (Should Fail)
```json
{
  "guest_details": [
    { "guest_type_name": "Infant", "guest_count": 1, "discount_id": 1 }
  ]
}
// ❌ Expected: Validation error - "Infants are free and cannot have discounts applied."
```

### Test 3: Booking with PWD (Should Fail)
```json
{
  "guest_discounts": [
    { "guest_type": "pwd", "count": 1, "discount_id": 1 }
  ]
}
// ❌ Expected: Validation error - "The selected guest type is invalid."
```

### Test 4: Booking with Infant in Breakdown
```json
{
  "guest_breakdown": {
    "adult": 5,
    "senior": 2,
    "child": 2,
    "infant": 1
  },
  "number_of_guests": 10
}
// ✅ Expected: Success - infant counted in total
```

---

## Files Modified

1. ✅ `app/Http/Requests/GuestEntry/StoreGuestEntryRequest.php`
   - Updated guest_type_name validation: `Adult,Child,Senior,Infant`
   - Added validation to prevent discount on Infant

2. ✅ `app/Http/Requests/Booking/StoreBookingRequest.php`
   - Removed `pwd` from guest_discounts validation
   - Changed `guest_breakdown.pwd` to `guest_breakdown.infant`
   - Updated all guest breakdown calculations

3. ✅ `app/Http/Requests/Booking/UpdateBookingRequest.php`
   - Removed `pwd` from guest_discounts validation

---

## Summary for Frontend Team

### Must Do:
1. ✅ Change guest type values: `Regular` → `Adult`, `Senior Citizen` → `Senior`, `Children below 2 yrs old` → `Infant`
2. ✅ Remove `PWD` from all guest type dropdowns
3. ✅ Add `infant` field to guest_breakdown (remove `pwd`)
4. ✅ Hide discount dropdown when `Infant` is selected (they're FREE)
5. ✅ Update guest_discounts to only use: `senior`, `child` (no `pwd`)

### UI Recommendations:
- Show "FREE" badge next to Infant guest type
- Auto-disable discount selection for Infant
- Display tooltip: "Infants (2 years and below) have free entrance"

---

**Status:** ✅ Backend ready - Frontend needs to update guest type labels and remove PWD references.
