# Testing Guide: Booking Overlap Detection & Manager Override

## Quick Test Scenarios

### Test 1: Create Normal Booking (No Conflicts)
**Purpose:** Verify normal booking creation still works

```bash
POST /api/bookings
Authorization: Bearer {your_token}
Content-Type: application/json

{
  "booking_type": "Package",
  "guest_name": "Test Guest 1",
  "contact_number": "09171234567",
  "check_in_date": "2025-12-01",
  "check_in_time": "14:00",
  "check_out_date": "2025-12-02",
  "check_out_time": "12:00",
  "number_of_guests": 5,
  "facilities": [
    {
      "facility_id": 1,
      "rate_id": 10,
      "quantity": 1,
      "rate_amount": 5000
    }
  ],
  "discount_mode": "None"
}
```

**Expected:** `201 Created` - Booking created successfully

---

### Test 2: Create Conflicting Booking (Within 2 Hours)
**Purpose:** Trigger the proximity warning

**Step 1:** Create first booking (check-out at 14:00)
```json
{
  "booking_type": "Package",
  "guest_name": "First Booking",
  "contact_number": "09171234567",
  "check_in_date": "2025-12-05",
  "check_in_time": "08:00",
  "check_out_date": "2025-12-05",
  "check_out_time": "14:00",
  "number_of_guests": 3,
  "facilities": [{"facility_id": 1, "rate_id": 10, "quantity": 1, "rate_amount": 5000}],
  "discount_mode": "None"
}
```

**Step 2:** Create second booking (check-in at 15:00 - only 1 hour gap)
```json
{
  "booking_type": "Package",
  "guest_name": "Second Booking",
  "contact_number": "09187654321",
  "check_in_date": "2025-12-05",
  "check_in_time": "15:00",
  "check_out_date": "2025-12-06",
  "check_out_time": "12:00",
  "number_of_guests": 4,
  "facilities": [{"facility_id": 1, "rate_id": 10, "quantity": 1, "rate_amount": 5000}],
  "discount_mode": "None"
}
```

**Expected:** `422 Unprocessable Entity`
```json
{
  "status": "warning",
  "message": "Booking conflicts detected. Manager override required.",
  "warning_type": "booking_proximity_conflict",
  "conflict_count": 1,
  "conflicts": [
    {
      "type": "proximity_before",
      "booking_reference": "BK-20251205-001",
      "guest_name": "First Booking",
      "gap_hours": 1.0,
      "severity": "critical",
      "message": "Booking BK-20251205-001 (Guest: First Booking) ends at Dec 05, 2025 02:00 PM, only 1.0 hours before the new booking starts"
    }
  ]
}
```

---

### Test 3: Manager Override with Valid Password
**Purpose:** Test successful override

```json
{
  "booking_type": "Package",
  "guest_name": "Second Booking",
  "contact_number": "09187654321",
  "check_in_date": "2025-12-05",
  "check_in_time": "15:00",
  "check_out_date": "2025-12-06",
  "check_out_time": "12:00",
  "number_of_guests": 4,
  "facilities": [{"facility_id": 1, "rate_id": 10, "quantity": 1, "rate_amount": 5000}],
  "discount_mode": "None",
  "manager_override": {
    "password": "your_manager_password",
    "reason": "Customer has urgent need for immediate booking"
  }
}
```

**Expected:** `201 Created` - Booking created with override logged

**Check logs:** `storage/logs/laravel.log` should contain:
```
✅ Manager override approved for booking proximity conflict
```

---

### Test 4: Wrong Password
**Purpose:** Test password validation

```json
{
  "booking_type": "Package",
  "guest_name": "Second Booking",
  "contact_number": "09187654321",
  "check_in_date": "2025-12-05",
  "check_in_time": "15:00",
  "check_out_date": "2025-12-06",
  "check_out_time": "12:00",
  "number_of_guests": 4,
  "facilities": [{"facility_id": 1, "rate_id": 10, "quantity": 1, "rate_amount": 5000}],
  "discount_mode": "None",
  "manager_override": {
    "password": "wrong_password",
    "reason": "Customer has urgent need for immediate booking"
  }
}
```

**Expected:** `401 Unauthorized`
```json
{
  "message": "Incorrect password. Please verify your credentials."
}
```

---

### Test 5: Non-Manager Tries to Override
**Purpose:** Test role-based authorization

Login as a **Staff** user, then try:
```json
{
  "booking_type": "Package",
  "guest_name": "Second Booking",
  "contact_number": "09187654321",
  "check_in_date": "2025-12-05",
  "check_in_time": "15:00",
  "check_out_date": "2025-12-06",
  "check_out_time": "12:00",
  "number_of_guests": 4,
  "facilities": [{"facility_id": 1, "rate_id": 10, "quantity": 1, "rate_amount": 5000}],
  "discount_mode": "None",
  "manager_override": {
    "password": "staff_password",
    "reason": "Customer has urgent need for immediate booking"
  }
}
```

**Expected:** `403 Forbidden`
```json
{
  "message": "Only Managers or Admins can override booking proximity warnings."
}
```

---

### Test 6: Reason Too Short
**Purpose:** Test reason validation

```json
{
  "booking_type": "Package",
  "guest_name": "Second Booking",
  "contact_number": "09187654321",
  "check_in_date": "2025-12-05",
  "check_in_time": "15:00",
  "check_out_date": "2025-12-06",
  "check_out_time": "12:00",
  "number_of_guests": 4,
  "facilities": [{"facility_id": 1, "rate_id": 10, "quantity": 1, "rate_amount": 5000}],
  "discount_mode": "None",
  "manager_override": {
    "password": "manager_password",
    "reason": "urgent"
  }
}
```

**Expected:** `422 Unprocessable Entity`
```json
{
  "message": "Override reason must be at least 10 characters long."
}
```

---

### Test 7: Multiple Conflicts
**Purpose:** Test multiple overlapping bookings

**Setup:** Create 3 bookings:
1. Booking A: 08:00 - 14:00
2. Booking B: 18:00 - 22:00
3. Now create: 15:00 - 17:00 (conflicts with both)

**Expected:** `422` with 2 conflicts in the array

---

### Test 8: Direct Overlap
**Purpose:** Test overlapping time periods

**Setup:**
- Booking A: 14:00 - 18:00
- Try to create: 16:00 - 20:00 (overlaps directly)

**Expected:** Conflict with `type: "direct_overlap"` and `severity: "critical"`

---

## Postman Collection

You can import this collection for easier testing:

```json
{
  "info": {
    "name": "Booking Overlap Tests",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "item": [
    {
      "name": "1. Normal Booking (No Conflict)",
      "request": {
        "method": "POST",
        "header": [],
        "body": {
          "mode": "raw",
          "raw": "{\n  \"booking_type\": \"Package\",\n  \"guest_name\": \"Test Guest 1\",\n  \"contact_number\": \"09171234567\",\n  \"check_in_date\": \"2025-12-01\",\n  \"check_in_time\": \"14:00\",\n  \"check_out_date\": \"2025-12-02\",\n  \"check_out_time\": \"12:00\",\n  \"number_of_guests\": 5,\n  \"facilities\": [{\"facility_id\": 1, \"rate_id\": 10, \"quantity\": 1, \"rate_amount\": 5000}],\n  \"discount_mode\": \"None\"\n}",
          "options": {
            "raw": {
              "language": "json"
            }
          }
        },
        "url": {
          "raw": "{{base_url}}/api/bookings",
          "host": ["{{base_url}}"],
          "path": ["api", "bookings"]
        }
      }
    },
    {
      "name": "2. Conflicting Booking (Triggers Warning)",
      "request": {
        "method": "POST",
        "header": [],
        "body": {
          "mode": "raw",
          "raw": "{\n  \"booking_type\": \"Package\",\n  \"guest_name\": \"Second Booking\",\n  \"contact_number\": \"09187654321\",\n  \"check_in_date\": \"2025-12-05\",\n  \"check_in_time\": \"15:00\",\n  \"check_out_date\": \"2025-12-06\",\n  \"check_out_time\": \"12:00\",\n  \"number_of_guests\": 4,\n  \"facilities\": [{\"facility_id\": 1, \"rate_id\": 10, \"quantity\": 1, \"rate_amount\": 5000}],\n  \"discount_mode\": \"None\"\n}",
          "options": {
            "raw": {
              "language": "json"
            }
          }
        },
        "url": {
          "raw": "{{base_url}}/api/bookings",
          "host": ["{{base_url}}"],
          "path": ["api", "bookings"]
        }
      }
    },
    {
      "name": "3. Manager Override (Valid)",
      "request": {
        "method": "POST",
        "header": [],
        "body": {
          "mode": "raw",
          "raw": "{\n  \"booking_type\": \"Package\",\n  \"guest_name\": \"Second Booking\",\n  \"contact_number\": \"09187654321\",\n  \"check_in_date\": \"2025-12-05\",\n  \"check_in_time\": \"15:00\",\n  \"check_out_date\": \"2025-12-06\",\n  \"check_out_time\": \"12:00\",\n  \"number_of_guests\": 4,\n  \"facilities\": [{\"facility_id\": 1, \"rate_id\": 10, \"quantity\": 1, \"rate_amount\": 5000}],\n  \"discount_mode\": \"None\",\n  \"manager_override\": {\n    \"password\": \"{{manager_password}}\",\n    \"reason\": \"Customer has urgent need for immediate booking\"\n  }\n}",
          "options": {
            "raw": {
              "language": "json"
            }
          }
        },
        "url": {
          "raw": "{{base_url}}/api/bookings",
          "host": ["{{base_url}}"],
          "path": ["api", "bookings"]
        }
      }
    }
  ],
  "variable": [
    {
      "key": "base_url",
      "value": "http://localhost:8000"
    },
    {
      "key": "manager_password",
      "value": "your_password_here"
    }
  ]
}
```

---

## Verification Checklist

After implementation, verify:

- [ ] Normal bookings work without conflicts
- [ ] Proximity detection triggers for < 2 hour gaps
- [ ] Warning response includes all conflict details
- [ ] Manager can override with valid password
- [ ] Wrong password returns 401 error
- [ ] Staff users get 403 forbidden
- [ ] Reason validation (min 10 chars) works
- [ ] Activity log records all override attempts
- [ ] Laravel logs show detailed override info
- [ ] Multiple conflicts shown correctly
- [ ] Direct overlaps marked as "critical"
- [ ] Severity levels calculated correctly

---

## Database Verification

Check activity log:
```sql
SELECT * FROM activity_log
WHERE description = 'Manager overrode booking proximity warning'
ORDER BY created_at DESC
LIMIT 5;
```

Check created bookings:
```sql
SELECT id, booking_reference, guest_name, check_in_datetime, check_out_datetime
FROM bookings
WHERE DATE(check_in_date) = '2025-12-05'
ORDER BY check_in_datetime;
```

---

## Troubleshooting

### Issue: No warning triggered
**Check:** Are the datetimes within 2 hours? Use `TIMESTAMPDIFF` in MySQL to verify gap.

### Issue: Override not logging
**Check:** Is Spatie Activity Log installed and configured?

### Issue: Password validation fails
**Check:** Password hashing - ensure using `Hash::check()` not plain comparison.

### Issue: Role check fails
**Check:** User has roles assigned via Spatie Permission package.

---

**Happy Testing!** 🚀
