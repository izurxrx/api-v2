# API Endpoints Reference Guide

**Purpose:** This document describes the purpose, logic, and expected usage of all API endpoints to validate frontend implementations.

---

## Authentication Endpoints

### POST `/login`
**Purpose:** Authenticate user and obtain access token  
**Logic:**
- Validates username and password
- Rate limited: 5 attempts per minute per username/IP
- Returns: User data + Sanctum token
- **Expected Usage:** Call once at app start, store token for subsequent requests

### POST `/logout`
**Purpose:** Invalidate current session token  
**Logic:** Deletes current access token from database  
**Expected Usage:** Call when user explicitly logs out

### GET `/user`
**Purpose:** Get authenticated user information  
**Logic:** Returns current user with roles and permissions  
**Expected Usage:** Call after login to verify session, refresh user data

---

## Booking Endpoints

### GET `/booking`
**Purpose:** List all bookings with filters  
**Logic:**
- Supports filtering: status, date range, guest name, reference number
- Supports pagination
- Returns bookings with facilities, billing, guest entry relationships
- **Expected Usage:** Main booking list view, apply filters as user changes search criteria

### POST `/booking`
**Purpose:** Create new booking reservation  
**Logic:**
- Creates booking with Pending status
- Automatically creates billing record (polymorphic)
- Calculates totals, downpayment requirements
- Validates facility availability for date range
- **Expected Usage:** Booking creation form submission
- **Note:** Does NOT accept payment - use `/billings/{id}/payment` or `/payments` after creation

### GET `/booking/summary`
**Purpose:** Get booking statistics for dashboard  
**Logic:**
- Returns today's check-ins/check-outs count
- Active bookings count
- Breakdown by booking type
- **Expected Usage:** Dashboard widgets, real-time statistics display

### GET `/booking/entrance-rates`
**Purpose:** Get available entrance rates for booking  
**Logic:**
- Returns entrance category rates only
- Filters out facility-specific rates
- Includes time slot information
- **Expected Usage:** Booking form - populate entrance rate dropdown

### GET `/booking/{id}`
**Purpose:** Get single booking details  
**Logic:**
- Returns complete booking with all relationships
- Includes: facilities, billing, payments, guest entry, discounts
- **Expected Usage:** Booking detail view, edit form population

### PUT `/booking/{id}`
**Purpose:** Update existing booking  
**Logic:**
- Only updates if status is Pending or Confirmed
- Cannot modify checked-in or checked-out bookings
- Recalculates totals if facilities changed
- Updates billing accordingly
- **Expected Usage:** Edit booking form submission (before check-in)

### POST `/booking/{id}/check-in`
**Purpose:** Check in a confirmed booking  
**Logic:**
- Changes status: Confirmed → Checked_In
- Creates GuestEntry record (links booking to active guest)
- Copies facilities from booking to guest entry
- Records actual check-in time
- **Expected Usage:** Reception desk - when guest physically arrives
- **Prerequisite:** Booking must be Confirmed (downpayment paid)

### POST `/booking/{id}/check-out`
**Purpose:** Check out a checked-in booking  
**Logic:**
- Changes status: Checked_In → Checked_Out
- Sets `check_out_datetime` and `actual_check_out_datetime` (CRITICAL for revenue)
- Calculates overstay charges if applicable
- Updates billing to Completed if fully paid
- **Expected Usage:** Reception desk - when guest departs
- **Prerequisite:** Booking must be Checked_In

### POST `/booking/{id}/cancel`
**Purpose:** Cancel a booking (Manager/Admin only)  
**Logic:**
- Changes status to Cancelled
- Voids billing if no payments made
- Processes refund if payments exist
- Records cancellation reason
- **Expected Usage:** Cancellation requests, no-shows
- **Restriction:** Cannot cancel checked-in bookings

---

## Guest Monitoring Endpoints (Walk-ins + Active Guests)

### GET `/guest-monitoring`
**Purpose:** List all guest entries with filters  
**Logic:**
- Returns walk-ins AND booking check-ins
- Supports filtering: entry type, date, checkout status
- Includes billing and payment information
- **Expected Usage:** Guest monitoring dashboard, search active guests

### POST `/guest-monitoring`
**Purpose:** Create walk-in guest entry  
**Logic:**
- Creates guest entry with Walk_In type
- Creates billing record automatically
- Calculates entrance fees + facility fees
- Applies discounts (PWD, Senior, Seasonal)
- **Expected Usage:** Walk-in registration at reception
- **Note:** Payment recorded separately via `/billings/{id}/payment`

### GET `/guest-monitoring/active`
**Purpose:** Get currently active (not checked-out) guests  
**Logic:**
- Filters: `is_checked_out = false`
- Filters by today's date
- Returns summary: total active guests, total entries
- **Expected Usage:** Real-time guest count display, occupancy monitoring

### GET `/guest-monitoring/today-summary`
**Purpose:** Get today's statistics  
**Logic:**
- Total entries today
- Active guests count
- Checked out count
- Revenue today
- Breakdown by entrance rate
- **Expected Usage:** Dashboard metrics for today's activity

### GET `/guest-monitoring/available-discounts`
**Purpose:** Get applicable discounts for walk-ins  
**Logic:**
- Returns Direct discounts (PWD, Senior Citizen, etc.)
- Returns active Seasonal discount (if valid today)
- Includes stacking rules
- **Expected Usage:** Walk-in form - populate discount options

### POST `/guest-monitoring/check-in-booking/{bookingId}`
**Purpose:** Alternative check-in method via guest monitoring  
**Logic:**
- Same as `/booking/{id}/check-in`
- Creates guest entry from booking
- Updates booking status to Checked_In
- **Expected Usage:** Alternative check-in workflow from guest monitoring screen

### GET `/guest-monitoring/{id}`
**Purpose:** Get single guest entry details  
**Logic:**
- Returns complete guest entry with billing, payments
- Includes facility usage, discounts applied
- **Expected Usage:** Guest detail view, checkout preparation

### PUT `/guest-monitoring/{id}`
**Purpose:** Update guest entry (before checkout)  
**Logic:**
- Update guest information, facilities
- Recalculates billing if changes made
- Cannot update after checkout
- **Expected Usage:** Correct guest information, add facilities mid-stay

### POST `/guest-monitoring/{id}/checkout`
**Purpose:** Check out a guest (walk-in or booking)  
**Logic:**
- Sets `checkout_datetime` (CRITICAL for revenue counting)
- Sets `is_checked_out = true`
- If linked to booking: updates booking status to Checked_Out
- Calculates final charges (overstay, extensions)
- **Expected Usage:** Reception desk - guest departure
- **Critical:** This is the ONLY way to mark revenue as complete

---

## Facility Endpoints

### GET `/facilities`
**Purpose:** List all facilities with filters  
**Logic:**
- Supports filtering: facility type, availability status
- Returns with rates and facility type information
- **Expected Usage:** Facility management, booking form facility selection

### GET `/facilities/walk-in`
**Purpose:** Get facilities available for walk-in use  
**Logic:**
- Returns facilities that can be rented on walk-in basis
- Filters by availability rules
- **Expected Usage:** Walk-in form - facility rental options

### POST `/facilities/booking`
**Purpose:** Get facilities available for booking  
**Logic:**
- Returns facilities that can be reserved in advance
- Includes rate information
- **Expected Usage:** Booking form - facility selection dropdown

### POST `/facilities/check-availability`
**Purpose:** Check multiple facilities availability  
**Logic:**
- Validates availability for given date/time range
- Checks against existing bookings and guest entries
- Returns conflicts if any
- **Expected Usage:** Booking form - real-time availability check before submission

### GET `/facilities/{facilityId}/availability`
**Purpose:** Check single facility availability  
**Logic:**
- Returns availability status for date range
- Lists conflicting bookings if unavailable
- **Expected Usage:** Facility detail view, calendar display

### GET `/facilities/{facilityId}/conflicts`
**Purpose:** Get detailed conflict information  
**Logic:**
- Returns all overlapping bookings/entries
- Shows exact conflict periods
- **Expected Usage:** Troubleshooting, calendar conflict resolution

### GET `/facilities/{id}`
**Purpose:** Get single facility details  
**Logic:** Returns complete facility with rates, type, availability settings  
**Expected Usage:** Facility detail view, edit form

### POST `/facilities`
**Purpose:** Create new facility  
**Expected Usage:** Facility management - add new facility

### PUT `/facilities/{id}`
**Purpose:** Update facility  
**Expected Usage:** Facility management - edit facility

### DELETE `/facilities/{id}`
**Purpose:** Soft delete facility  
**Expected Usage:** Facility management - deactivate facility

---

## Rate Endpoints

### GET `/rates`
**Purpose:** List all rates with filters  
**Logic:**
- Supports filtering: rate category (Facility/Entrance/Exclusive), rate type
- **Expected Usage:** Rate management, pricing displays

### GET `/rates/{id}`
**Purpose:** Get single rate details  
**Expected Usage:** Rate detail view

### POST `/rates`
**Purpose:** Create new rate  
**Expected Usage:** Rate management

### PUT `/rates/{id}`
**Purpose:** Update rate  
**Expected Usage:** Rate management

### DELETE `/rates/{id}`
**Purpose:** Soft delete rate  
**Expected Usage:** Rate management

---

## Payment Endpoints

### GET `/payments`
**Purpose:** List all payments with filters  
**Logic:**
- Supports filtering: payment method, date range, billing ID
- Returns with billing and billable (booking/guest entry) relationships
- **Expected Usage:** Payment history, financial reports

### POST `/payments`
**Purpose:** Record a new payment  
**Logic:**
- Links to billing record
- Updates billing amounts (amount_paid, balance)
- Updates billing payment_status (unpaid/partial/paid)
- Triggers booking status update if downpayment met (Pending → Confirmed)
- Generates payment number automatically
- **Expected Usage:** Payment collection at reception
- **Critical:** This triggers the automatic Pending → Confirmed transition

### GET `/payments/summary`
**Purpose:** Get payment statistics  
**Logic:**
- Total payments by method
- Total payments by date range
- **Expected Usage:** Financial dashboard, daily summary reports

### GET `/payments/billing/{billingId}`
**Purpose:** Get all payments for specific billing  
**Logic:** Returns payment history for a billing record  
**Expected Usage:** Billing detail view, payment tracking

### GET `/payments/{id}`
**Purpose:** Get single payment details  
**Expected Usage:** Payment receipt view, audit

### POST `/payments/{id}/reverse`
**Purpose:** Reverse/refund a payment (Manager/Admin only)  
**Logic:**
- Creates reversal payment record
- Updates billing amounts
- Does NOT delete original payment (audit trail)
- **Expected Usage:** Refund processing, payment corrections
- **Restriction:** Manager/Admin only

### DELETE `/payments/{id}` ⚠️ DEPRECATED
**Purpose:** Returns 410 Gone  
**Logic:** Payment deletion disabled for audit compliance  
**Expected Usage:** DO NOT USE - use reverse instead

---

## Billing Endpoints

### GET `/billings`
**Purpose:** List all billing records with filters  
**Logic:**
- Supports filtering: payment status, billing status, date range
- Returns with billable (booking/guest entry) and payments
- **Expected Usage:** Financial management, billing tracking

### GET `/billings/unpaid`
**Purpose:** Get all unpaid/partial billings  
**Logic:**
- Filters: payment_status IN ('unpaid', 'partial')
- **Expected Usage:** Accounts receivable, collection follow-up

### GET `/billings/summary`
**Purpose:** Get billing statistics  
**Logic:**
- Total billings by status
- Total outstanding balances
- **Expected Usage:** Financial dashboard

### GET `/billings/by-reference`
**Purpose:** Get billing by booking/guest entry reference number  
**Logic:**
- Search by booking number or guest entry reference
- **Expected Usage:** Quick billing lookup by reference

### GET `/billings/{id}`
**Purpose:** Get single billing details  
**Logic:** Returns complete billing with all payments and billable  
**Expected Usage:** Billing detail view, payment processing

### POST `/billings/{id}/payment`
**Purpose:** Record payment for billing  
**Logic:**
- Same as POST `/payments` but scoped to specific billing
- Updates billing amounts
- Triggers booking status updates
- **Expected Usage:** Payment collection (alternative to `/payments` endpoint)

### POST `/billings/{id}/cancel`
**Purpose:** Cancel/void billing (Manager/Admin only)  
**Logic:**
- Voids billing if no payments
- Processes refund if payments exist
- Updates booking/guest entry status
- **Expected Usage:** Cancellation processing
- **Restriction:** Manager/Admin only

---

## Discount Endpoints

### GET `/discounts`
**Purpose:** List all discounts  
**Logic:**
- Returns active and inactive discounts
- Categories: Direct (PWD, Senior), Seasonal, Manual
- **Expected Usage:** Discount management

### GET `/discounts/{id}`
**Purpose:** Get discount details  
**Expected Usage:** Discount detail view

### POST `/discounts`
**Purpose:** Create new discount  
**Expected Usage:** Discount configuration

### PUT `/discounts/{id}`
**Purpose:** Update discount  
**Expected Usage:** Discount management

### DELETE `/discounts/{id}`
**Purpose:** Soft delete discount  
**Expected Usage:** Deactivate discount

---

## Facility Type Endpoints

### GET `/facility-types`
**Purpose:** List all facility types  
**Expected Usage:** Facility type management, categorization

### POST `/facility-types`
**Purpose:** Create facility type  
**Expected Usage:** Setup, configuration

### PUT `/facility-types/{id}`
**Purpose:** Update facility type  
**Expected Usage:** Configuration management

### DELETE `/facility-types/{id}`
**Purpose:** Soft delete facility type  
**Expected Usage:** Remove unused types

---

## User Management Endpoints

### GET `/users`
**Purpose:** List all users  
**Expected Usage:** User management dashboard

### POST `/users`
**Purpose:** Create new user  
**Expected Usage:** User registration

### GET `/users/{id}`
**Purpose:** Get user details  
**Expected Usage:** User profile

### PUT `/users/{id}`
**Purpose:** Update user  
**Expected Usage:** User profile edit

### DELETE `/users/{id}`
**Purpose:** Soft delete user  
**Expected Usage:** Deactivate user account

### POST `/users/{id}/restore`
**Purpose:** Restore deleted user  
**Expected Usage:** Reactivate account

---

## Role Endpoints

### GET `/roles`
**Purpose:** List all available roles  
**Logic:** Returns roles with permissions  
**Expected Usage:** User creation/edit - role selection

---

## Report Endpoints

### GET `/reports/revenue`
**Purpose:** Generate revenue report  
**Logic:**
- Filters by date range
- Groups by: booking revenue, walk-in revenue
- **CRITICAL:** Only counts completed transactions with checkout dates
- Booking revenue: WHERE `check_out_datetime` OR `actual_check_out_datetime` IS SET
- Walk-in revenue: WHERE `checkout_datetime` IS SET
- **Expected Usage:** Financial reporting, revenue analysis
- **Important:** Revenue is recognized on CHECKOUT date, not payment date (accrual basis)

### GET `/reports/revenue/export/excel`
**Purpose:** Export revenue report to Excel  
**Expected Usage:** Download financial data

### GET `/reports/revenue/export/pdf`
**Purpose:** Export revenue report to PDF  
**Expected Usage:** Print financial reports

### GET `/reports/filters`
**Purpose:** Get available report filters  
**Expected Usage:** Report form - populate filter options

### GET `/reports/date-presets`
**Purpose:** Get date range presets (Today, This Week, This Month, etc.)  
**Expected Usage:** Report form - quick date selection

---

## Dashboard Endpoints

### GET `/dashboard/booked-today`
**Purpose:** Get today's bookings  
**Logic:** Returns bookings with check_in_date = today  
**Expected Usage:** Dashboard widget

### GET `/dashboard/upcoming-bookings`
**Purpose:** Get upcoming bookings  
**Logic:** Returns confirmed bookings for next 7 days  
**Expected Usage:** Dashboard widget, schedule preview

### GET `/dashboard/total-guest-today`
**Purpose:** Get total guests present today  
**Logic:** Counts active guest entries  
**Expected Usage:** Dashboard widget, occupancy counter

---

## Critical Workflow Rules

### Booking Lifecycle
1. **Create Booking** → Status: Pending, Billing created
2. **Record Payment** (downpayment) → Status: Pending → **Confirmed** (automatic)
3. **Check In** → Status: Confirmed → Checked_In, GuestEntry created
4. **Record Payment** (balance) → Optional
5. **Check Out** → Status: Checked_In → Checked_Out, Sets `check_out_datetime` ✅
6. **Revenue Counted** → Query uses `check_out_datetime` to find completed bookings

### Walk-in Lifecycle
1. **Create Guest Entry** → Billing created
2. **Record Payment** → Full payment expected
3. **Checkout** → Sets `checkout_datetime` ✅
4. **Revenue Counted** → Query uses `checkout_datetime` to find completed walk-ins

### Payment Processing Rules
- Payments ALWAYS go through Billing model
- Booking status changes are AUTOMATIC based on payment thresholds
- Downpayment threshold met → Pending → Confirmed (NO manual status update needed)
- DO NOT call separate "confirm booking" endpoint - it happens automatically

### Revenue Recognition Rules
- Revenue is counted on **checkout date**, not payment date (accrual accounting)
- Bookings: Must have `check_out_datetime` OR `actual_check_out_datetime` set
- Walk-ins: Must have `checkout_datetime` set
- Without checkout, transaction is NOT counted in revenue (even if paid)

### Field Naming Critical Differences
- **Booking** uses: `check_out_datetime` (with underscore)
- **GuestEntry** uses: `checkout_datetime` (no underscore)
- These are DIFFERENT fields - use correct one per model

### Status Transition Rules
**Booking:**
- Pending → Confirmed (automatic on downpayment)
- Confirmed → Checked_In (manual check-in)
- Checked_In → Checked_Out (manual checkout)
- Any → Cancelled (manual cancellation)

**Billing:**
- pending → confirmed (downpayment met)
- confirmed → active (check-in)
- active → completed (checkout + full payment)

**Payment Status:**
- unpaid → partial (first payment)
- partial → paid (balance paid)

---

## Frontend Validation Checklist

✅ **DO:**
- Use `/payments` or `/billings/{id}/payment` to record payments
- Wait for automatic status transitions (Pending → Confirmed)
- Call checkout endpoints to trigger revenue recognition
- Use correct field names per model type
- Check availability before creating bookings
- Filter active guests by `is_checked_out = false`

❌ **DON'T:**
- Try to manually change booking status to Confirmed (it's automatic)
- Delete payments (use reverse instead)
- Expect revenue without checkout datetime
- Confuse `check_out_datetime` (Booking) with `checkout_datetime` (GuestEntry)
- Skip checkout - it's required for revenue counting
- Use deprecated endpoints (check status codes)

---

## Common Integration Patterns

### Pattern: Create Booking + Accept Downpayment
```
1. POST /booking (creates booking + billing)
2. POST /payments or /billings/{id}/payment (records downpayment)
   → Booking automatically changes to Confirmed if threshold met
3. Frontend should refresh booking to get new status
```

### Pattern: Check In Guest
```
1. POST /booking/{id}/check-in
   → Creates GuestEntry, changes status to Checked_In
2. Frontend navigates to guest monitoring view
```

### Pattern: Check Out + Collect Balance
```
1. POST /payments (collect remaining balance)
2. POST /guest-monitoring/{id}/checkout
   → Sets checkout_datetime, updates booking
3. Revenue is now counted for this transaction
```

### Pattern: Walk-in Flow
```
1. GET /guest-monitoring/available-discounts (get discount options)
2. POST /guest-monitoring (create walk-in + billing)
3. POST /payments (collect payment)
4. POST /guest-monitoring/{id}/checkout (when guest leaves)
   → Sets checkout_datetime for revenue counting
```

### Pattern: Generate Revenue Report
```
1. GET /reports/date-presets (get quick date options)
2. GET /reports/revenue?from={date}&to={date}
   → Returns revenue for all checked-out transactions
3. Optionally: GET /reports/revenue/export/excel
```

---

**Last Updated:** November 17, 2025  
**API Version:** Laravel 11 with Sanctum Authentication  
**Revenue Model:** Accrual basis (recognized on checkout date)
