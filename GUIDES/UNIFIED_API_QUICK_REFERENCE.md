# Quick Reference: Unified Walk-in API

## Single Request Format for Walk-ins and Bookings

### Create Walk-in (Same structure as Booking)

```http
POST /api/guest-monitoring
Content-Type: application/json
Authorization: Bearer {token}
```

```json
{
  "entrance_rate_id": 44,
  "guest_name": "John Doe",
  "contact_number": "09123456789",
  "entry_date": "2025-11-19",
  "check_in_time": "09:00",
  
  "guest_breakdown": {
    "adult": 2,
    "senior": 1,
    "child": 0
  },
  
  "guest_discounts": [
    {
      "guest_type": "senior",
      "count": 1,
      "discount_id": 3
    }
  ],
  
  "facilities": [
    {
      "facility_id": 15,
      "rate_id": 201,
      "quantity": 1
    }
  ],
  
  "seasonal_discount_id": null,
  "manual_discount_amount": 0,
  "notes": "Birthday celebration"
}
```

## Key Points

✅ **Unified:** Walk-ins and bookings now use same structure  
✅ **Backward Compatible:** Old `guest_details` structure still works  
✅ **Frontend:** Can reuse same form for both  
✅ **Difference:** Only `entry_date` (today vs future)

## Guest Types

| Unified Format | Legacy Format |
|----------------|---------------|
| `adult` | `Regular` |
| `senior` | `Senior Citizen` |
| `child` | `Children below 2 yrs old` |

## Frontend Example

```javascript
// Same form for both walk-ins and bookings
function createGuestEntry(isAdvance) {
  const data = {
    guest_breakdown: {
      adult: adultCount,
      senior: seniorCount,
      child: childCount
    },
    guest_discounts: getAppliedDiscounts(),
    facilities: getSelectedFacilities(),
    
    // Only difference:
    entry_date: isAdvance ? futureDate : today
  };
  
  if (isAdvance) {
    // POST /api/bookings (booking creation)
    return createBooking(data);
  } else {
    // POST /api/guest-monitoring (walk-in creation)
    return createWalkIn(data);
  }
}
```

## Documentation

- **Full Guide:** `GUEST_MONITORING_FRONTEND_GUIDE.md`
- **Change Summary:** `WALK_IN_BOOKING_UNIFICATION.md`
- **Test Script:** `test_unified_walk_in_structure.php`
