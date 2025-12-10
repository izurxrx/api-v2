# Booking Overlap Detection & Manager Override - Implementation Summary

## 🎯 Overview

This feature adds a **2-hour proximity check** for bookings, requiring **manager approval with password verification** when bookings are too close together. This prevents operational issues while allowing flexibility through authorized overrides.

---

## ✅ What Was Implemented

### Backend Components

1. **BookingOverlapService** (`app/Services/BookingOverlapService.php`)
   - Detects bookings within 2-hour proximity window
   - Checks for direct overlaps
   - Calculates severity levels (critical, high, medium, low)
   - Provides detailed conflict information

2. **BookingController Updates** (`app/Http/Controllers/Api/BookingController.php`)
   - Integrated overlap detection in `store()` method
   - Added `validateManagerOverride()` private method
   - Multi-layer security validation:
     - Authentication check (Sanctum token)
     - Role verification (Manager/Admin only)
     - Password re-authentication
     - Reason validation (min 10 characters)
   - Comprehensive audit logging

3. **Security Features**
   - Password verification using `Hash::check()`
   - Role-based access control via Spatie Permission
   - Activity log integration for audit trail
   - Detailed logging of all override attempts

---

## 🔄 User Flow

```
1. User creates booking
   ↓
2. Backend checks proximity (< 2 hours?)
   ↓
3a. No conflict → Create booking (201)
   ↓
3b. Conflict detected → Return warning (422)
   ↓
4. Frontend shows modal with conflicts
   ↓
5. Manager enters password + reason
   ↓
6. Backend validates:
   - Is user authenticated? ✓
   - Has Manager/Admin role? ✓
   - Password correct? ✓
   - Reason provided (≥10 chars)? ✓
   ↓
7. Create booking + log override (201)
```

---

## 📡 API Endpoints

### POST `/api/bookings`

**Without Override (Initial Attempt):**
```json
{
  "booking_type": "Package",
  "guest_name": "John Doe",
  "check_in_date": "2025-12-05",
  "check_in_time": "15:00",
  "check_out_date": "2025-12-06",
  "check_out_time": "12:00",
  "number_of_guests": 5,
  "facilities": [...]
}
```

**Response (422 - Conflict Detected):**
```json
{
  "status": "warning",
  "warning_type": "booking_proximity_conflict",
  "conflicts": [
    {
      "type": "proximity_before",
      "booking_reference": "BK-20251205-001",
      "guest_name": "Jane Smith",
      "gap_hours": 1.0,
      "severity": "critical",
      "message": "Booking BK-20251205-001 ends at 2:00 PM, only 1.0 hours before..."
    }
  ],
  "requires_manager_override": true
}
```

**With Manager Override:**
```json
{
  "booking_type": "Package",
  "guest_name": "John Doe",
  // ... other fields ...
  "manager_override": {
    "password": "manager_password",
    "reason": "Customer emergency requiring quick turnaround"
  }
}
```

**Response (201 - Success):**
```json
{
  "status": "success",
  "message": "Booking created successfully",
  "data": { /* booking details */ }
}
```

---

## 🔒 Security Layers

| Layer | Check | Error Response |
|-------|-------|----------------|
| 1 | User authenticated (Sanctum) | 401 Unauthenticated |
| 2 | Has Manager/Admin role | 403 Only Managers can override |
| 3 | Password verification | 401 Incorrect password |
| 4 | Reason provided | 422 Reason required |
| 5 | Reason length (≥10 chars) | 422 Reason too short |

---

## 📊 Conflict Types & Severity

### Conflict Types
- **`proximity_before`**: Previous booking ends too close to new check-in
- **`proximity_after`**: Next booking starts too close to new check-out
- **`direct_overlap`**: Bookings overlap in time

### Severity Levels
| Severity | Gap Time | UI Suggestion |
|----------|----------|---------------|
| `critical` | < 1 hour | 🔴 Red background |
| `high` | 1-1.5 hours | 🟠 Orange background |
| `medium` | 1.5-2 hours | 🔵 Blue background |
| `low` | 2+ hours | 🟢 Green background |

---

## 📝 Audit Trail

Every override attempt is logged:

**Laravel Log** (`storage/logs/laravel.log`):
```
[2025-11-24] INFO: ✅ Manager override approved for booking proximity conflict
{
  "manager_id": 5,
  "manager_name": "John Manager",
  "override_reason": "Customer emergency",
  "conflicts": [...],
  "severity_levels": ["critical"]
}
```

**Activity Log** (database):
```sql
SELECT * FROM activity_log
WHERE description = 'Manager overrode booking proximity warning'
```

---

## 📚 Documentation Files

1. **[FRONTEND_BOOKING_OVERLAP_IMPLEMENTATION.md](./FRONTEND_BOOKING_OVERLAP_IMPLEMENTATION.md)**
   - Complete frontend implementation guide
   - React & Vue examples
   - CSS styling suggestions
   - Security best practices
   - Testing checklist

2. **[BOOKING_OVERLAP_TESTING.md](./BOOKING_OVERLAP_TESTING.md)**
   - API test scenarios
   - Postman collection
   - Verification checklist
   - Database queries
   - Troubleshooting guide

3. **This file (BOOKING_OVERLAP_SUMMARY.md)**
   - Quick reference
   - Implementation overview

---

## 🚀 Frontend Integration Steps

1. **Detect Conflict Response**
   ```javascript
   if (error.response?.status === 422 &&
       error.response?.data?.warning_type === 'booking_proximity_conflict') {
     // Show override modal
   }
   ```

2. **Show Modal with Conflicts**
   - Display all conflicts with severity styling
   - Check if user has Manager/Admin role
   - Show password + reason fields if authorized

3. **Resubmit with Override**
   ```javascript
   payload.manager_override = {
     password: managerPassword,
     reason: overrideReason
   };
   ```

4. **Handle Responses**
   - 201: Success → Close modal, show confirmation
   - 401: Wrong password → Show error message
   - 403: Not authorized → Show "contact manager" message
   - 422: Validation error → Show specific error

---

## ✨ Key Features

✅ **Smart Detection**
- 2-hour proximity window (configurable)
- Checks both before and after new booking
- Direct overlap detection
- Automatic severity calculation

✅ **Secure Override**
- Password re-authentication required
- Role-based authorization
- Minimum reason length validation
- Complete audit trail

✅ **User Experience**
- Clear conflict details
- Severity-based styling
- Manager-only override option
- Helpful error messages

✅ **Audit & Compliance**
- All attempts logged (success & failure)
- Activity log integration
- Detailed conflict information
- Who, what, when, why tracking

---

## 🔧 Configuration

### Proximity Window
Default: **2 hours**

To change, update the `checkProximity()` call in BookingController:
```php
$overlapCheck = $this->overlapService->checkProximity(
    $checkInDateTime,
    $checkOutDateTime,
    null,
    3 // Change to 3 hours
);
```

### Reason Minimum Length
Default: **10 characters**

To change, update validation in `validateManagerOverride()`:
```php
if (strlen(trim($overrideData['reason'])) < 15) { // Change to 15
    abort(422, 'Override reason must be at least 15 characters long.');
}
```

---

## 🧪 Quick Test

```bash
# 1. Create first booking (ends at 14:00)
curl -X POST http://localhost:8000/api/bookings \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "booking_type": "Package",
    "guest_name": "First Guest",
    "check_in_date": "2025-12-05",
    "check_in_time": "08:00",
    "check_out_date": "2025-12-05",
    "check_out_time": "14:00",
    "number_of_guests": 3,
    "facilities": [{"facility_id": 1, "rate_id": 10, "quantity": 1, "rate_amount": 5000}]
  }'

# 2. Try to create second booking (starts at 15:00 - only 1 hour gap)
# Expected: 422 with warning

# 3. Retry with manager override
# Expected: 201 success
```

---

## 📞 Support

For questions or issues:
1. Check [FRONTEND_BOOKING_OVERLAP_IMPLEMENTATION.md](./FRONTEND_BOOKING_OVERLAP_IMPLEMENTATION.md) for frontend details
2. Review [BOOKING_OVERLAP_TESTING.md](./BOOKING_OVERLAP_TESTING.md) for testing scenarios
3. Check `storage/logs/laravel.log` for detailed backend logs
4. Verify user roles in database: `SELECT * FROM model_has_roles WHERE model_id = USER_ID`

---

## 🎯 Success Criteria

- [x] Backend detects bookings within 2-hour window
- [x] Returns structured warning response (422)
- [x] Validates manager role before override
- [x] Verifies password using Hash::check()
- [x] Requires meaningful reason (≥10 chars)
- [x] Logs all override attempts
- [x] Creates booking after successful override
- [x] Frontend guide with React/Vue examples
- [x] Testing guide with Postman collection
- [x] Complete documentation

---

**Implementation Date:** November 24, 2025
**Backend:** Laravel 10 + Sanctum + Spatie Permission
**Feature Status:** ✅ Complete & Ready for Frontend Integration
