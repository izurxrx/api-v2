# Discount System Quick Reference Card

## 🎯 Discount Modes

| Mode | Stacking Rules | Applies To |
|------|---------------|------------|
| **Direct** | ❌ Cannot stack with Seasonal<br>✅ Can stack with Manual | Per guest type |
| **Seasonal** | ❌ Cannot stack with Direct<br>✅ Can stack with Manual | All guests |
| **Manual** | ✅ Can stack with Direct OR Seasonal<br>✅ Can be used alone | Total amount |
| **None** | ✅ Can add Manual on top | - |

---

## 🔗 API Quick Reference

### Get Discounts
```bash
# Direct discounts (for per-guest dropdown)
GET /api/discounts?category=Direct_Discount&active_only=1

# Seasonal discounts (for seasonal dropdown)
GET /api/discounts?category=Seasonal_Discount&active_only=1
```

### Walk-in Entry
```bash
POST /api/guest-monitoring
```

### Booking
```bash
POST /api/bookings
```

---

## 📝 Request Payload Examples

### Direct Discount (Walk-in)
```json
{
  "guest_details": [
    {
      "guest_type_name": "Senior Citizen",
      "guest_count": 2,
      "discount_id": 1
    },
    {
      "guest_type_name": "Regular",
      "guest_count": 3,
      "discount_id": null
    }
  ],
  "seasonal_discount_id": null,
  "manual_discount_amount": 0
}
```

### Seasonal Discount (Walk-in)
```json
{
  "guest_details": [
    {
      "guest_type_name": "Regular",
      "guest_count": 5,
      "discount_id": null
    }
  ],
  "seasonal_discount_id": 1,
  "manual_discount_amount": 0
}
```

### Direct + Manual (Walk-in)
```json
{
  "guest_details": [
    {
      "guest_type_name": "Senior Citizen",
      "guest_count": 2,
      "discount_id": 1
    }
  ],
  "seasonal_discount_id": null,
  "manual_discount_amount": 100.00
}
```

### Direct Discount (Booking)
```json
{
  "booking_type": "Swimming",
  "discount_mode": "Direct",
  "guest_discounts": [
    {
      "guest_type": "senior",
      "count": 2,
      "discount_id": 1
    },
    {
      "guest_type": "pwd",
      "count": 1,
      "discount_id": 2
    }
  ],
  "discount_id": null,
  "manual_discount_amount": 0
}
```

### Seasonal Discount (Booking)
```json
{
  "booking_type": "Swimming",
  "discount_mode": "Seasonal",
  "guest_discounts": null,
  "discount_id": 1,
  "manual_discount_amount": 0
}
```

### Manual Only (Booking)
```json
{
  "booking_type": "Package",
  "discount_mode": "Manual",
  "guest_discounts": null,
  "discount_id": null,
  "manual_discount_amount": 500.00
}
```

---

## 🚫 Validation Rules

### ❌ These Will Be REJECTED:

```javascript
// 1. Direct + Seasonal together
{
  "guest_details": [{ "discount_id": 1 }],  // Direct
  "seasonal_discount_id": 2                  // Seasonal
}
// Error: "Cannot apply both Direct and Seasonal discounts"

// 2. Package booking with Direct/Seasonal
{
  "booking_type": "Package",
  "discount_mode": "Direct"  // or "Seasonal"
}
// Error: "Package bookings can only have Manual discounts"

// 3. Direct mode without guest_discounts (Booking)
{
  "discount_mode": "Direct",
  "guest_discounts": []  // Empty
}
// Error: "Direct discount mode requires specifying which guests receive the discount"
```

---

## 💡 Frontend Logic Snippets

### Determine Discount Mode (Walk-in)
```javascript
const getDiscountMode = () => {
  const hasDirectDiscounts = guest_details.some(g => g.discount_id !== null);
  const hasSeasonalDiscount = seasonal_discount_id !== null;
  const hasManualDiscount = manual_discount_amount > 0;
  
  if (hasDirectDiscounts) return 'Direct';
  if (hasSeasonalDiscount) return 'Seasonal';
  if (hasManualDiscount) return 'Manual';
  return 'None';
};
```

### Validate Before Submit
```javascript
const validateDiscounts = () => {
  const hasDirectDiscounts = guest_details.some(g => g.discount_id !== null);
  const hasSeasonalDiscount = seasonal_discount_id !== null;
  
  // Rule 1: Cannot have both Direct and Seasonal
  if (hasDirectDiscounts && hasSeasonalDiscount) {
    alert('Cannot apply both Direct and Seasonal discounts');
    return false;
  }
  
  // Rule 2: Package bookings - Manual only
  if (bookingType === 'Package') {
    if (discountMode === 'Direct' || discountMode === 'Seasonal') {
      alert('Package bookings can only have Manual discounts');
      return false;
    }
  }
  
  return true;
};
```

### Show/Hide Discount Fields
```javascript
const [discountMode, setDiscountMode] = useState('None');

// Direct mode: Show discount dropdown per guest
{discountMode === 'Direct' && (
  <select name="discount_id">
    <option value="">No Discount</option>
    {directDiscounts.map(d => (
      <option key={d.id} value={d.id}>{d.name}</option>
    ))}
  </select>
)}

// Seasonal mode: Show seasonal discount dropdown
{discountMode === 'Seasonal' && (
  <select name="seasonal_discount_id">
    <option value="">No Seasonal Discount</option>
    {seasonalDiscounts.map(d => (
      <option key={d.id} value={d.id}>{d.name}</option>
    ))}
  </select>
)}

// Manual: Always available (optional)
<input 
  type="number" 
  name="manual_discount_amount"
  min="0"
  step="0.01"
  placeholder="0.00"
  defaultValue={0}
/>
```

---

## 🧪 Quick Test Cases

| # | Test | Expected Result |
|---|------|----------------|
| 1 | Direct only | ✅ Success |
| 2 | Seasonal only | ✅ Success |
| 3 | Manual only | ✅ Success |
| 4 | Direct + Manual | ✅ Success |
| 5 | Seasonal + Manual | ✅ Success |
| 6 | None (no discounts) | ✅ Success |
| 7 | Direct + Seasonal | ❌ Error 422 |
| 8 | Package + Direct | ❌ Error 422 |
| 9 | Package + Seasonal | ❌ Error 422 |

---

## 📊 Calculation Flow

```
1. Base Amount
   └─ (guest_count × entrance_rate)

2. Direct Discount (if mode = Direct)
   └─ Discount per guest type

3. Seasonal Discount (if mode = Seasonal)  
   └─ Discount on total entrance

4. Facilities
   └─ Add facility charges

5. Manual Discount (if provided)
   └─ Final deduction

6. Total = max(0, result)
```

---

## 🎨 UI Pattern

```
┌────────────────────────────────────────────────┐
│ Discount Mode:                                 │
│ ( ) None  (•) Direct  ( ) Seasonal  ( ) Manual│
├────────────────────────────────────────────────┤
│                                                │
│ [Show appropriate fields based on mode]        │
│                                                │
│ ┌────────────────────────────────────────┐    │
│ │ ✓ Add Manual Discount (optional)       │    │
│ │   Amount: [___________]                │    │
│ └────────────────────────────────────────┘    │
└────────────────────────────────────────────────┘
```

---

## 🔑 Key Takeaways

1. **Direct = Per guest type** (Senior, PWD, Child get individual discounts)
2. **Seasonal = All guests** (One discount applies to everyone)
3. **Manual = Always optional** (Can be added to any mode)
4. **Direct + Seasonal = NEVER** (Mutually exclusive)
5. **Package = Manual ONLY** (No Direct/Seasonal)

---

**Full Documentation:** See `DISCOUNT_SYSTEM_SUMMARY.md`
