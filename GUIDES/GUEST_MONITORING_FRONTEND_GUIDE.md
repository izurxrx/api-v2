# Guest Monitoring Frontend Implementation Guide

**Last Updated:** November 19, 2025  
**Module:** Guest Monitoring (Walk-ins & Booking Check-ins)  
**API Version:** 2.0

---

## Table of Contents

1. [Overview](#overview)
2. [Guest Entry Types](#guest-entry-types)
3. [Guest Entry Lifecycle](#guest-entry-lifecycle)
4. [API Endpoints](#api-endpoints)
5. [Discount System](#discount-system)
6. [Facility Release System](#facility-release-system)
7. [Checkout vs Completion](#checkout-vs-completion)
8. [Frontend Display Logic](#frontend-display-logic)
9. [Request Examples](#request-examples)
10. [Response Structures](#response-structures)

---

## Overview

The Guest Monitoring module handles **two types of entries**:

1. **Walk-in Entries** - Spontaneous visits without reservation
2. **Booking Check-ins** - Guests arriving for advance reservations

**Key Difference:**
- Walk-ins are created directly in Guest Monitoring
- Bookings are checked in through Guest Monitoring (already exist in Bookings module)

---

## Guest Entry Types

### 1. Walk-in Entry (Day-Use)
```
Entry Type: "Walk_In"
Booking: null
Booking Type: N/A
Facilities: Can rent facilities that require entrance fee
Entrance Fee: Required
Checkout: Auto-completes when paid + facilities released
```

### 2. Swimming Booking Check-in (Day-Use)
```
Entry Type: "Booking"
Booking: exists
Booking Type: "Swimming"
Facilities: Pre-selected facilities that require entrance fee
Entrance Fee: Required
Checkout: Auto-completes when paid + facilities released
```

### 3. Package Booking Check-in (Overnight)
```
Entry Type: "Booking"
Booking: exists
Booking Type: "Package"
Facilities: Accommodation (rooms, villas)
Entrance Fee: NOT required
Checkout: Requires formal checkout process
```

---

## Guest Entry Lifecycle

### Walk-in / Swimming Booking Flow:
```
1. Check-in
   ↓
2. Use Facilities (active)
   ↓
3. Staff releases facilities (one by one or all at once)
   ↓
4. Guest pays balance
   ↓
5. System auto-completes ✅
   ↓
6. Guest leaves freely
```

### Package Booking Flow:
```
1. Check-in
   ↓
2. Use Facilities (overnight stay)
   ↓
3. Staff initiates checkout
   ↓
4. System calculates balance + overtime (if any)
   ↓
5. Guest pays balance
   ↓
6. Staff confirms checkout ✅
   ↓
7. Booking marked as Checked_Out
```

---

## API Endpoints

### Base URL
```
/api/guest-monitoring
```

### 1. List All Guest Entries
**Endpoint:** `GET /api/guest-monitoring`  
**Permission:** `view-walk-ins`

**Query Parameters:**
- `page` - Page number (default: 1)
- `per_page` - Items per page (default: 15)
- `search` - Search by name, reference, contact
- `status` - Filter by status: `active`, `completed`
- `entry_type` - Filter by type: `Walk_In`, `Booking`

**Response:**
```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "entry_reference": "SWIM-20251119-001",
      "entry_type": "Walk_In",
      "guest_name": "John Doe",
      "contact_number": "09123456789",
      "check_in_datetime": "2025-11-19 09:00:00",
      "is_checked_out": false,
      "checkout_datetime": null,
      "booking_type": null,
      "payment_status": "partial",
      "balance": 500.00,
      "status": "active"
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 15,
    "total": 45,
    "last_page": 3
  }
}
```

---

### 2. Create Walk-in Entry
**Endpoint:** `POST /api/guest-monitoring`  
**Permission:** `process-walk-ins`

**✅ UPDATED: Unified Structure (Same as Booking API)**

Walk-ins now accept the same request structure as bookings, making it easy to reuse the same form for both immediate and advance reservations.

**Two Input Formats Supported:**

#### Option 1: Unified Structure (RECOMMENDED - Same as Bookings)
```json
{
  "entrance_rate_id": 44,
  "guest_name": "John Doe",
  "contact_number": "09123456789",
  "entry_date": "2025-11-19",
  "check_in_time": "09:00",
  "number_of_guests": 10,
  
  // ✅ NEW: Same structure as bookings
  "guest_breakdown": {
    "adult": 7,
    "senior": 2,
    "child": 1
  },
  
  // ✅ NEW: Same structure as bookings
  "guest_discounts": [
    {
      "guest_type": "senior",
      "count": 2,
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

#### Option 2: Legacy Structure (Still Supported)
```json
{
  "entrance_rate_id": 44,
  "guest_name": "John Doe",
  "contact_number": "09123456789",
  "entry_date": "2025-11-19",
  "check_in_time": "09:00",
  "number_of_guests": 10,
  
  // ⚠️ OLD: Legacy structure (still works)
  "guest_details": [
    {
      "guest_type_name": "Regular",
      "guest_count": 7,
      "discount_id": 5
    },
    {
      "guest_type_name": "Senior Citizen",
      "guest_count": 2,
      "discount_id": 3
    },
    {
      "guest_type_name": "Children below 2 yrs old",
      "guest_count": 1,
      "discount_id": null
    }
  ],
  
  "facilities": [...],
  "seasonal_discount_id": null,
  "manual_discount_amount": 0,
  "notes": "Birthday celebration"
}
```

**Key Differences:**

| Field | Unified (New) | Legacy (Old) |
|-------|--------------|--------------|
| Guest counts | `guest_breakdown` (adult/senior/child) | `guest_details` array |
| Guest types | `adult`, `senior`, `child` | `Regular`, `Senior Citizen`, `Children below 2 yrs old` |
| Discounts | `guest_discounts` array | `discount_id` in each `guest_details` entry |
| Auto-conversion | System converts to internal format | Used directly |

**💡 Frontend Recommendation:**
Use the unified structure (`guest_breakdown` + `guest_discounts`) for consistency with the Booking API. This allows you to use the same form for both walk-ins and advance bookings.

**Discount Mode Values (Auto-Determined):**
- `None` - No discount
- `Direct` - PWD/Senior/Student discount per guest
- `Seasonal` - Seasonal promotion discount
- `Manual` - Staff-entered discount
- `Direct+Manual` - Combination
- `Seasonal+Manual` - Combination

> Note: discount_mode is automatically determined based on which discounts are provided. Don't send it in the request.

**Response:**
```json
{
  "status": "success",
  "message": "Walk-in entry created successfully",
  "data": {
    "id": 123,
    "entry_reference": "SWIM-20251119-001",
    "entry_type": "Walk_In",
    "guest_name": "John Doe",
    "contact_number": "09123456789",
    "check_in_datetime": "2025-11-19 09:00:00",
    "entrance_rate": {
      "id": 44,
      "rate_name": "Day Swimming",
      "time_duration_hours": 8
    },
    "guest_details": [
      {
        "guest_type_name": "Adult",
        "guest_count": 7,
        "base_rate": 250.00,
        "discount_amount": 50.00,
        "final_rate": 200.00,
        "total_amount": 1400.00
      }
    ],
    "facilities": [
      {
        "id": 15,
        "facility_name": "Cottage A1",
        "rate": 500.00,
        "start_datetime": "2025-11-19 09:00:00",
        "end_datetime": "2025-11-19 18:00:00",
        "is_released": false
      }
    ],
    "billing": {
      "billing_number": "BIL-20251119-001",
      "subtotal": 3500.00,
      "discount_amount": 300.00,
      "total_amount": 3200.00,
      "amount_paid": 0.00,
      "balance": 3200.00,
      "payment_status": "unpaid",
      "billing_status": "active"
    }
  }
}
```

---

### 3. Check-in a Booking
**Endpoint:** `POST /api/guest-monitoring/check-in-booking/{bookingId}`  
**Permission:** `check-in-guests`

**Request Body:**
```json
{
  "check_in_datetime": "2025-11-19 14:00:00",
  "notes": "Early check-in approved"
}
```

**Response:** Same structure as walk-in creation, but includes booking reference and type.

---

### 4. Get Guest Entry Details
**Endpoint:** `GET /api/guest-monitoring/{id}`  
**Permission:** `view-walk-ins`

**Response:**
```json
{
  "status": "success",
  "data": {
    "id": 123,
    "entry_reference": "SWIM-20251119-001",
    "entry_type": "Walk_In",
    "booking_type": null,
    "guest_name": "John Doe",
    "contact_number": "09123456789",
    "check_in_datetime": "2025-11-19 09:00:00",
    "is_checked_out": false,
    "checkout_datetime": null,
    "entrance_rate": {
      "id": 44,
      "rate_name": "Day Swimming",
      "time_duration_hours": 8,
      "expected_exit_time": "2025-11-19 17:00:00"
    },
    "guest_details": [...],
    "facilities": [
      {
        "extension_id": 456,
        "facility_id": 15,
        "facility_name": "Cottage A1",
        "rate": 500.00,
        "start_datetime": "2025-11-19 09:00:00",
        "end_datetime": "2025-11-19 18:00:00",
        "is_released": false,
        "released_at": null
      }
    ],
    "billing": {
      "billing_number": "BIL-20251119-001",
      "subtotal": 3500.00,
      "discount_amount": 300.00,
      "total_amount": 3200.00,
      "amount_paid": 2700.00,
      "balance": 500.00,
      "payment_status": "partial",
      "billing_status": "active",
      "payments": [
        {
          "payment_number": "PAY-20251119-001",
          "amount": 2700.00,
          "payment_method": "cash",
          "payment_date": "2025-11-19 09:05:00"
        }
      ]
    }
  }
}
```

---

### 5. Release Facility
**Endpoint:** `POST /api/guest-monitoring/{guestEntryId}/release-facility/{extensionId}`  
**Permission:** `process-walk-ins`

**Purpose:** Mark a facility as returned/released (for day-use guests)

**Request Body:**
```json
{
  "notes": "Cottage returned in good condition"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Facility released successfully",
  "data": {
    "extension_id": 456,
    "facility": "Cottage A1",
    "released_at": "2025-11-19 15:30:00",
    "released_by": "Staff Member Name"
  }
}
```

**Auto-Completion Logic:**
When the LAST facility is released AND balance = 0:
- `is_checked_out` = true
- `checkout_datetime` = now()
- `billing_status` = 'completed'
- Guest can leave freely ✅

---

### 6. Preview Checkout (Package Bookings Only)
**Endpoint:** `POST /api/guest-monitoring/{id}/preview-checkout`  
**Permission:** `checkout-walk-ins`

**Request Body:**
```json
{
  "checkout_datetime": "2025-11-20 11:00:00",
  "apply_overtime": true
}
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "guest_entry_id": 123,
    "current_balance": 2500.00,
    "overtime": {
      "has_overtime": true,
      "total": 800.00,
      "details": [
        {
          "facility": "Room 101",
          "hours_over": 2,
          "rate_per_hour": 400.00,
          "amount": 800.00
        }
      ]
    },
    "new_balance": 3300.00
  }
}
```

---

### 7. Checkout Guest (Package Bookings Only)
**Endpoint:** `POST /api/guest-monitoring/{id}/checkout`  
**Permission:** `checkout-walk-ins`

**Request Body:**
```json
{
  "checkout_datetime": "2025-11-20 11:00:00",
  "apply_overtime": false,
  "notes": "Room inspected, all clear"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Guest checked out successfully",
  "data": {
    "entry_reference": "BOOK-20251119-001",
    "checkout_datetime": "2025-11-20 11:00:00",
    "is_checked_out": true,
    "billing": {
      "balance": 2500.00,
      "payment_status": "partial"
    },
    "overtime": {
      "has_overtime": false
    }
  }
}
```

---

## Discount System

### Walk-ins and Swimming Bookings Support Same Discounts:

**1. Direct Discounts** (PWD, Senior, Student)
- Applied per guest type in `guest_details`
- Fixed percentage from discounts table

**2. Seasonal Discounts** (Promotions)
- Applied to total entrance fee
- Time-based eligibility

**3. Manual Discounts** (Staff discretion)
- Fixed amount entered by staff
- Requires manager approval in some cases

**4. Combination Discounts**
- `Direct+Manual` - Direct discount + manual discount
- `Seasonal+Manual` - Seasonal discount + manual discount

**Package Bookings:**
- Only support **Manual discounts**
- No Direct or Seasonal discounts allowed

---

## Facility Release System

### For Day-Use Guests (Walk-ins & Swimming Bookings):

**Purpose:** Track when guests return/release facilities

**How It Works:**
1. Staff clicks "Release Facility" button for each facility
2. System marks `is_released = true` in `billing_extensions`
3. When ALL facilities released + balance = 0 → Auto-complete
4. Guest can leave freely without formal checkout

**UI Display:**
```
┌────────────────────────────────────────┐
│ Facilities Rented                      │
├────────────────────────────────────────┤
│ Cottage A1                             │
│ Status: In Use                         │
│ [Release Facility] button              │
├────────────────────────────────────────┤
│ Pool Access                            │
│ Status: Released ✓                     │
│ Released at: 3:30 PM by Staff1         │
└────────────────────────────────────────┘
```

---

## Checkout vs Completion

### Terminology Guide:

| Guest Type | UI Term | What Happens | Button Shown |
|------------|---------|--------------|--------------|
| Walk-in | "Complete Entry" | Auto-completes when paid + facilities released | [Release All Facilities] |
| Swimming Booking | "Complete Entry" | Auto-completes when paid + facilities released | [Release All Facilities] |
| Package Booking | "Checkout Guest" | Staff manually checks out | [Checkout Guest] |

**Backend Field:**
- `is_checked_out` = true (for all types)
- `checkout_datetime` = completion/checkout time
- `billing_status` = 'completed'

**Frontend Display Logic:**
```javascript
function getStatusLabel(guestEntry) {
  if (guestEntry.is_checked_out) {
    return "Completed"; // Show "Completed" for all
  }
  return "Active"; // Currently using facilities
}

function showCheckoutButton(guestEntry) {
  // Only show checkout for Package bookings
  return guestEntry.booking?.booking_type === 'Package';
}

function showReleaseFacilityButtons(guestEntry) {
  // Show for Walk-ins and Swimming bookings
  const isDayUse = guestEntry.entry_type === 'Walk_In' || 
                   guestEntry.booking?.booking_type === 'Swimming';
  return isDayUse && !guestEntry.is_checked_out;
}
```

---

## Frontend Display Logic

### Guest List Table

```javascript
const columns = [
  { field: 'entry_reference', header: 'Reference' },
  { field: 'guest_name', header: 'Guest Name' },
  { field: 'entry_type', header: 'Type' }, // Walk_In or Booking
  { field: 'booking_type', header: 'Booking Type' }, // Swimming/Package/null
  { field: 'check_in_datetime', header: 'Check-in Time' },
  { field: 'status', header: 'Status' }, // Active or Completed
  { field: 'payment_status', header: 'Payment' }, // Paid/Partial/Unpaid
  { field: 'balance', header: 'Balance' },
];

// Status badge colors
function getStatusColor(status) {
  return status === 'active' ? 'blue' : 'green';
}

// Payment status badge colors
function getPaymentColor(status) {
  return {
    'paid': 'green',
    'partial': 'orange',
    'unpaid': 'red'
  }[status];
}
```

---

### Guest Details Page

```javascript
<div className="guest-details">
  {/* Header */}
  <div className="header">
    <h2>{guestEntry.entry_reference}</h2>
    <StatusBadge status={guestEntry.is_checked_out ? 'completed' : 'active'} />
  </div>

  {/* Guest Information */}
  <InfoSection>
    <Field label="Guest Name" value={guestEntry.guest_name} />
    <Field label="Contact" value={guestEntry.contact_number} />
    <Field label="Entry Type" value={guestEntry.entry_type} />
    {guestEntry.booking && (
      <Field label="Booking Type" value={guestEntry.booking.booking_type} />
    )}
    <Field label="Check-in" value={formatDateTime(guestEntry.check_in_datetime)} />
    {guestEntry.is_checked_out && (
      <Field label="Completed At" value={formatDateTime(guestEntry.checkout_datetime)} />
    )}
  </InfoSection>

  {/* Entrance Rate Info */}
  {guestEntry.entrance_rate && (
    <InfoSection title="Entrance Rate">
      <Field label="Rate" value={guestEntry.entrance_rate.rate_name} />
      <Field label="Duration" value={`${guestEntry.entrance_rate.time_duration_hours} hours`} />
      <Field label="Expected Exit" value={formatDateTime(guestEntry.entrance_rate.expected_exit_time)} />
    </InfoSection>
  )}

  {/* Guest Breakdown */}
  <Table title="Guest Details" data={guestEntry.guest_details} />

  {/* Facilities */}
  <div className="facilities-section">
    <h3>Facilities Rented</h3>
    {guestEntry.facilities.map(facility => (
      <FacilityCard
        key={facility.extension_id}
        facility={facility}
        onRelease={() => releaseFacility(facility.extension_id)}
        showReleaseButton={showReleaseFacilityButtons(guestEntry)}
      />
    ))}
  </div>

  {/* Billing Summary */}
  <BillingSummary billing={guestEntry.billing} />

  {/* Action Buttons */}
  <div className="actions">
    {showCheckoutButton(guestEntry) && (
      <Button onClick={handleCheckout}>Checkout Guest</Button>
    )}
    {showReleaseFacilityButtons(guestEntry) && (
      <Button onClick={handleReleaseAll}>Release All Facilities</Button>
    )}
    <Button onClick={handleProcessPayment}>Process Payment</Button>
  </div>
</div>
```

---

### Facility Card Component

```javascript
const FacilityCard = ({ facility, onRelease, showReleaseButton }) => {
  return (
    <div className={`facility-card ${facility.is_released ? 'released' : 'active'}`}>
      <div className="facility-header">
        <h4>{facility.facility_name}</h4>
        {facility.is_released ? (
          <Badge color="green">Released ✓</Badge>
        ) : (
          <Badge color="blue">In Use</Badge>
        )}
      </div>
      
      <div className="facility-details">
        <Field label="Rate" value={formatCurrency(facility.rate)} />
        <Field label="Start" value={formatDateTime(facility.start_datetime)} />
        <Field label="End" value={formatDateTime(facility.end_datetime)} />
      </div>

      {facility.is_released && (
        <div className="release-info">
          <p>Released at: {formatDateTime(facility.released_at)}</p>
        </div>
      )}

      {showReleaseButton && !facility.is_released && (
        <Button 
          variant="outline" 
          size="sm" 
          onClick={onRelease}
        >
          Release Facility
        </Button>
      )}
    </div>
  );
};
```

---

## Request Examples

### Example 1: Create Walk-in with Direct Discount
```javascript
POST /api/guest-monitoring
{
  "entrance_rate_id": 44,
  "guest_name": "Maria Santos",
  "contact_number": "09123456789",
  "check_in_datetime": "2025-11-19 10:00:00",
  "total_guests": 5,
  "guest_breakdown": {
    "adult": 3,
    "senior": 2
  },
  "guest_details": [
    {
      "guest_type_name": "Adult",
      "rate_id": 101,
      "guest_count": 3,
      "discount_mode": "None"
    },
    {
      "guest_type_name": "Senior",
      "rate_id": 102,
      "guest_count": 2,
      "discount_mode": "Direct",
      "discount_id": 3
    }
  ],
  "facilities": [
    {
      "facility_id": 15,
      "rate_id": 201,
      "quantity": 1,
      "start_datetime": "2025-11-19 10:00:00",
      "end_datetime": "2025-11-19 18:00:00"
    }
  ],
  "discount_mode": "Direct",
  "discount_id": 3
}
```

### Example 2: Release Facility
```javascript
POST /api/guest-monitoring/123/release-facility/456
{
  "notes": "Cottage returned at 3:30 PM, all items intact"
}
```

### Example 3: Checkout Package Booking
```javascript
POST /api/guest-monitoring/123/checkout
{
  "checkout_datetime": "2025-11-20 11:00:00",
  "apply_overtime": false,
  "notes": "Room inspected, no damage"
}
```

---

## Response Structures

### Success Response
```json
{
  "status": "success",
  "message": "Operation completed successfully",
  "data": { ... }
}
```

### Error Response
```json
{
  "status": "error",
  "message": "Error message here",
  "errors": {
    "field_name": ["Validation error message"]
  }
}
```

### Common Error Codes

| Code | Message | Cause |
|------|---------|-------|
| 403 | Unauthorized | Missing permission |
| 404 | Not found | Guest entry doesn't exist |
| 400 | Facility already released | Already released |
| 400 | Cannot checkout walk-in entry | Trying to checkout day-use guest |
| 422 | Validation failed | Invalid input data |

---

## Best Practices

### 1. Real-time Updates
- Poll guest list every 30 seconds for active entries
- Update payment status immediately after payment
- Refresh facility status after release

### 2. User Feedback
- Show loading states during API calls
- Display success/error messages clearly
- Confirm destructive actions (release, checkout)

### 3. Status Indicators
- Use color-coded badges for status
- Show progress indicators for multi-step processes
- Display warnings for overdue guests

### 4. Mobile Responsiveness
- Optimize facility cards for mobile view
- Use collapsible sections for details
- Provide quick action buttons

### 5. Error Handling
- Graceful degradation on network errors
- Clear validation messages
- Retry logic for failed operations

---

## Summary

**Key Takeaways:**

1. **Walk-ins and Swimming Bookings** behave the same:
   - Day-use only
   - Auto-complete when paid + facilities released
   - No formal checkout needed

2. **Package Bookings** are different:
   - Overnight stays
   - Require formal checkout
   - Can have overtime charges

3. **Facility Release** system:
   - Staff marks facilities as released
   - When all released + paid → auto-complete
   - Guests leave freely

4. **Discount System**:
   - Day-use: All discount types supported
   - Package: Manual discounts only

5. **Status Display**:
   - "Active" = Currently using facilities
   - "Completed" = Done, can leave

---

**End of Guide**
