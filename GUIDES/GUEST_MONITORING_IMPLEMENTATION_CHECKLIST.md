# Guest Monitoring Frontend Implementation Checklist

**Purpose:** Ensure frontend correctly implements transaction completion workflows  
**Date:** November 19, 2025  
**Status:** ✅ Ready for Implementation

---

## Quick Reference: Completion Logic

| Entry Type | Completion Method | Trigger | Status Field |
|------------|------------------|---------|--------------|
| **Walk-in** | 🤖 Auto-complete | Paid + All facilities released | `is_checked_out = true` |
| **Swimming** | 🤖 Auto-complete | Paid + All facilities released | `is_checked_out = true` |
| **Package** | 👤 Manual | Staff clicks Checkout + Paid | `is_checked_out = true` |

---

## SECTION 1: UI Requirements by Entry Type

### A. Walk-in Entry Card

```typescript
interface WalkInCard {
  // Required displays
  entryReference: string;        // "SWIM-20251119-001"
  guestName: string;
  numberOfGuests: number;
  checkInTime: string;
  
  // Status badges
  entryTypeBadge: "Walk_In";     // Blue badge
  statusBadge: "Active" | "Completed";
  
  // Facility tracking (CRITICAL)
  facilities: FacilityItem[];
  facilitiesReleased: number;    // Count of released facilities
  facilitiesTotal: number;       // Total facilities
  
  // Payment tracking
  billing: {
    total: number;
    paid: number;
    balance: number;
    paymentStatus: "unpaid" | "partial" | "paid";
  };
  
  // Auto-completion indicator
  autoCompleteProgress: {
    allFacilitiesReleased: boolean;
    fullyPaid: boolean;
    readyToComplete: boolean;     // Both conditions met
  };
  
  // Action buttons
  actions: {
    releaseFacility: boolean;     // Show if has unreleased facilities
    viewDetails: boolean;         // Always true
    collectPayment: boolean;      // Show if balance > 0
  };
}

interface FacilityItem {
  id: number;
  extensionId: number;           // For release API call
  name: string;
  isReleased: boolean;
  releasedAt?: string;
  releasedBy?: string;
  releaseButton: boolean;        // Show button if not released
}
```

**UI Layout:**
```
┌────────────────────────────────────────────────────┐
│ SWIM-20251119-001  [Walk_In]  [Active]             │
│ John Doe | 5 guests | Check-in: 09:00 AM           │
├────────────────────────────────────────────────────┤
│ FACILITIES (2/3 Released)                          │
│  ✅ Small Cottage #3                               │
│     Released: 2:00 PM by Staff A                   │
│  ✅ Medium Cottage #5                              │
│     Released: 2:15 PM by Staff A                   │
│  ❌ Large Cottage #2  [Release Facility ➜]        │
├────────────────────────────────────────────────────┤
│ PAYMENT                                            │
│  Total: ₱2,500.00                                  │
│  Paid: ₱2,500.00 ✅                                │
│  Balance: ₱0.00                                    │
├────────────────────────────────────────────────────┤
│ ⏳ AUTO-COMPLETION STATUS                          │
│  ✅ Fully paid                                     │
│  ⏳ Waiting for 1 facility to be released          │
│  → Will auto-complete when all facilities released │
├────────────────────────────────────────────────────┤
│ [View Details]  [View Receipt]                     │
└────────────────────────────────────────────────────┘
```

**Implementation Checklist:**
- [ ] Display entry type badge (blue "Walk_In")
- [ ] Show facility list with release status icons (✅/❌)
- [ ] Show release button per unreleased facility
- [ ] Display release timestamp and staff name
- [ ] Show facility release progress: "2/3 Released"
- [ ] Display payment status with color coding
- [ ] Show auto-completion status indicator
- [ ] Hide checkout button (not applicable for day-use)
- [ ] Update UI in real-time after facility release
- [ ] Show success message when auto-completed

---

### B. Swimming Booking Card

```typescript
interface SwimmingBookingCard {
  // Same as Walk-in PLUS:
  bookingReference: string;      // "BOOK-20251118-012"
  bookingType: "Swimming";
  entryTypeBadge: "Booking";     // Green badge
  
  // All other fields same as Walk-in
  // Completion logic: AUTO-COMPLETE (same as walk-in)
}
```

**UI Layout:**
```
┌────────────────────────────────────────────────────┐
│ SWIM-20251119-005  [Booking]  [Active]             │
│ Jane Smith | 8 guests | Check-in: 10:30 AM         │
│ Booking: BOOK-20251118-012 (Swimming)              │
├────────────────────────────────────────────────────┤
│ FACILITIES (All Released ✅)                       │
│  ✅ Small Cottage #1                               │
│     Released: 3:00 PM by Staff B                   │
│  ✅ Medium Cottage #3                              │
│     Released: 3:05 PM by Staff B                   │
├────────────────────────────────────────────────────┤
│ PAYMENT                                            │
│  Total: ₱3,200.00                                  │
│  Paid: ₱3,200.00 ✅                                │
│  Balance: ₱0.00                                    │
├────────────────────────────────────────────────────┤
│ ✅ READY TO AUTO-COMPLETE                          │
│  ✅ All facilities released                        │
│  ✅ Fully paid                                     │
│  → System will complete automatically              │
├────────────────────────────────────────────────────┤
│ [View Details]  [View Receipt]                     │
└────────────────────────────────────────────────────┘
```

**Implementation Checklist:**
- [ ] Display entry type badge (green "Booking")
- [ ] Show booking reference and type
- [ ] All facility release features (same as walk-in)
- [ ] Show auto-completion status indicator
- [ ] Hide checkout button (not applicable)
- [ ] Link to original booking details

---

### C. Package Booking Card

```typescript
interface PackageBookingCard {
  // Required displays
  entryReference: string;
  bookingReference: string;
  guestName: string;
  numberOfGuests: number;
  checkInTime: string;
  
  // Status badges
  entryTypeBadge: "Booking";     // Green badge
  bookingTypeBadge: "Package";   // Purple badge
  statusBadge: "Active" | "Completed";
  
  // Accommodation details
  accommodation: {
    name: string;                // "Villa #2"
    scheduledCheckout: string;   // "Tomorrow 12:00 PM"
    actualCheckout?: string;     // When staff checks out
  };
  
  // Overtime calculation
  overtime?: {
    isLate: boolean;
    lateBy: string;              // "3 hours 25 minutes"
    overtimeHours: number;       // 3.25
    overtimeFee: number;         // 1462.50
    canApply: boolean;           // Staff can choose
  };
  
  // Payment tracking
  billing: {
    total: number;
    paid: number;
    balance: number;
    paymentStatus: "unpaid" | "partial" | "paid";
  };
  
  // NO auto-completion indicator
  // Manual checkout only
  
  // Action buttons
  actions: {
    previewCheckout: boolean;    // Always true if active
    checkout: boolean;           // True if active
    viewDetails: boolean;
    collectPayment: boolean;     // Show if balance > 0
  };
}
```

**UI Layout (Active):**
```
┌────────────────────────────────────────────────────┐
│ SWIM-20251119-010  [Booking]  [Package]  [Active]  │
│ Bob Johnson | 4 guests | Check-in: Yesterday 2:00PM│
│ Booking: BOOK-20251118-008                         │
├────────────────────────────────────────────────────┤
│ ACCOMMODATION                                      │
│  Villa #2                                          │
│  Scheduled checkout: Today 12:00 PM                │
│  Current time: 3:30 PM ⚠️ (3h 30m late)           │
├────────────────────────────────────────────────────┤
│ PAYMENT                                            │
│  Total: ₱8,500.00                                  │
│  Paid: ₱5,000.00                                   │
│  Balance: ₱3,500.00 ⚠️                             │
├────────────────────────────────────────────────────┤
│ ⚠️ MANUAL CHECKOUT REQUIRED                        │
│  → Staff must click checkout to complete           │
│  → Overtime can be optionally applied              │
├────────────────────────────────────────────────────┤
│ [Preview Checkout]  [Checkout]  [Collect Payment] │
└────────────────────────────────────────────────────┘
```

**Implementation Checklist:**
- [ ] Display entry and booking type badges
- [ ] Show accommodation details (no facility list)
- [ ] Display scheduled vs actual checkout time
- [ ] Calculate and show if guest is late
- [ ] Show "Manual Checkout Required" indicator
- [ ] NO auto-completion status (not applicable)
- [ ] Show Preview Checkout button
- [ ] Show Checkout button
- [ ] NO facility release buttons

---

## SECTION 2: API Integration Guide

### A. Facility Release (Day-Use Only)

**When to call:** User clicks "Release Facility" button

```typescript
async function releaseFacility(
  guestEntryId: number,
  extensionId: number,
  notes?: string
) {
  const response = await fetch(
    `/api/guest-monitoring/${guestEntryId}/release-facility/${extensionId}`,
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify({ notes })
    }
  );
  
  if (response.ok) {
    const data = await response.json();
    
    // Update UI:
    // 1. Mark facility as released ✅
    // 2. Show release timestamp and staff
    // 3. Update progress counter
    // 4. Check if auto-completion conditions met
    // 5. Show success toast
    
    updateFacilityStatus(extensionId, {
      isReleased: true,
      releasedAt: data.data.released_at,
      releasedBy: data.data.released_by
    });
    
    // Re-fetch guest entry to check if auto-completed
    await refreshGuestEntry(guestEntryId);
  }
}
```

**Auto-completion Detection:**
```typescript
function checkIfAutoCompleted(guestEntry: GuestEntry) {
  if (guestEntry.is_checked_out) {
    // Entry was auto-completed!
    showSuccessMessage('Entry completed automatically!');
    updateStatusBadge('Completed');
    moveToCompletedList(guestEntry.id);
    playSuccessAnimation();
  }
}
```

---

### B. Preview Checkout (Package Only)

**When to call:** User clicks "Preview Checkout" button

```typescript
async function previewCheckout(
  guestEntryId: number,
  exitDate: string,
  exitTime: string
) {
  const response = await fetch(
    `/api/guest-monitoring/${guestEntryId}/preview-checkout`,
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify({
        exit_date: exitDate,    // "2025-11-20"
        exit_time: exitTime      // "15:30"
      })
    }
  );
  
  const data = await response.json();
  
  // Show preview modal with:
  return {
    scheduledEnd: data.scheduled_end,
    actualEnd: data.actual_end,
    isLate: data.is_late,
    overtimeCharges: data.overtime_charges,  // Array of charges
    overtimeTotal: data.overtime_total,
    currentBalance: data.current_balance,
    finalTotal: data.final_total
  };
}
```

**Preview Modal UI:**
```
┌─────────────────────────────────────────────────┐
│ Checkout Preview - Villa #2                     │
├─────────────────────────────────────────────────┤
│ Scheduled checkout: Today 12:00 PM              │
│ Selected checkout:  Today 3:30 PM               │
│ Status: ⚠️ 3 hours 30 minutes late              │
├─────────────────────────────────────────────────┤
│ OVERTIME CALCULATION                            │
│                                                 │
│ Villa #2:                                       │
│  - Base stay:     Paid                          │
│  - Overtime:      3.25 hours                    │
│  - Rate:          ₱450/hour                     │
│  - Grace period:  15 minutes (applied)          │
│  - Overtime fee:  ₱1,462.50                     │
├─────────────────────────────────────────────────┤
│ PAYMENT SUMMARY                                 │
│  Current balance:    ₱3,500.00                  │
│  + Overtime fee:     ₱1,462.50                  │
│  ────────────────────────────                   │
│  Final total:        ₱4,962.50                  │
├─────────────────────────────────────────────────┤
│ ☐ Apply overtime charges                       │
│   (Uncheck to waive late fees)                 │
├─────────────────────────────────────────────────┤
│ [Cancel]  [Proceed to Checkout]                │
└─────────────────────────────────────────────────┘
```

---

### C. Checkout (Package Only)

**When to call:** User confirms checkout in preview modal

```typescript
async function checkout(
  guestEntryId: number,
  exitDate: string,
  exitTime: string,
  applyOvertime: boolean,
  notes?: string
) {
  const response = await fetch(
    `/api/guest-monitoring/${guestEntryId}/checkout`,
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify({
        exit_date: exitDate,
        exit_time: exitTime,
        apply_overtime: applyOvertime,  // CRITICAL: Staff choice
        notes
      })
    }
  );
  
  if (response.ok) {
    const data = await response.json();
    
    // Success scenarios:
    if (data.data.is_checked_out) {
      // Completed successfully
      showSuccessMessage('Checkout completed!');
      updateStatusBadge('Completed');
      moveToCompletedList(guestEntryId);
      
    } else {
      // Pending payment
      showWarningMessage(
        `Checkout saved. Balance: ₱${data.data.billing.balance}`
      );
      refreshGuestEntry(guestEntryId);
    }
    
  } else {
    const error = await response.json();
    
    // Handle errors:
    if (error.message.includes('outstanding balance')) {
      // Payment required
      showPaymentModal(guestEntryId, error.balance);
    } else {
      showErrorMessage(error.message);
    }
  }
}
```

**Checkout Flow:**
```typescript
// Step 1: Preview
const preview = await previewCheckout(id, date, time);

// Step 2: Show modal with overtime option
const modal = showCheckoutModal({
  ...preview,
  onConfirm: async (applyOvertime) => {
    
    // Step 3: Check payment
    if (preview.finalTotal > 0) {
      const shouldCollectPayment = await confirmPaymentCollection();
      if (shouldCollectPayment) {
        await redirectToPayment(id);
        return;
      }
    }
    
    // Step 4: Execute checkout
    await checkout(id, date, time, applyOvertime);
  }
});
```

---

## SECTION 3: Status Management

### A. Determining Entry Status

```typescript
function getEntryStatus(guestEntry: GuestEntry): EntryStatus {
  if (guestEntry.is_checked_out) {
    return 'Completed';
  }
  
  // Check auto-completion conditions for day-use
  const isDayUse = 
    guestEntry.entry_type === 'Walk_In' ||
    guestEntry.booking?.booking_type === 'Swimming';
  
  if (isDayUse) {
    const allFacilitiesReleased = guestEntry.billing.extensions
      .filter(ext => ext.extension_type === 'facility')
      .every(ext => ext.is_released);
    
    const fullyPaid = 
      guestEntry.billing.balance === 0 &&
      guestEntry.billing.payment_status === 'paid';
    
    if (allFacilitiesReleased && fullyPaid) {
      return 'Ready to Auto-Complete';
    }
  }
  
  return 'Active';
}
```

### B. Status Badge Colors

```css
.status-badge {
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
}

.status-active {
  background: #FEF3C7;   /* Yellow-100 */
  color: #92400E;        /* Yellow-900 */
}

.status-completed {
  background: #D1FAE5;   /* Green-100 */
  color: #065F46;        /* Green-900 */
}

.status-pending-payment {
  background: #FEE2E2;   /* Red-100 */
  color: #991B1B;        /* Red-900 */
}

.entry-type-walkin {
  background: #DBEAFE;   /* Blue-100 */
  color: #1E40AF;        /* Blue-800 */
}

.entry-type-booking {
  background: #D1FAE5;   /* Green-100 */
  color: #065F46;        /* Green-900 */
}

.booking-type-package {
  background: #E9D5FF;   /* Purple-100 */
  color: #6B21A8;        /* Purple-800 */
}
```

---

## SECTION 4: Testing Checklist

### A. Walk-in Entry Tests

- [ ] **Test 1: Release facilities one by one**
  - Create walk-in with 2 facilities
  - Pay full amount
  - Release first facility → Should still be Active
  - Release second facility → Should auto-complete

- [ ] **Test 2: Pay after releasing facilities**
  - Create walk-in with 1 facility
  - Release facility first
  - Pay balance → Should auto-complete

- [ ] **Test 3: Partial payment**
  - Create walk-in
  - Pay partial amount
  - Release all facilities
  - Entry should stay Active (not auto-complete)
  - Pay remaining balance → Should auto-complete

- [ ] **Test 4: No facilities**
  - Create walk-in with no facilities
  - Pay full amount → Should auto-complete immediately

---

### B. Swimming Booking Tests

- [ ] **Test 5: Check-in booking**
  - Check in swimming booking
  - Verify entry created with correct type
  - Verify facilities transferred from booking

- [ ] **Test 6: Auto-completion updates booking**
  - Check in swimming booking
  - Release facilities and pay
  - Verify booking status changed to 'Checked_Out'
  - Verify actual_check_out_datetime updated

---

### C. Package Booking Tests

- [ ] **Test 7: Checkout on time (no overtime)**
  - Check in package booking
  - Preview checkout at scheduled time
  - Verify no overtime calculated
  - Checkout without applying overtime
  - Verify completed if paid

- [ ] **Test 8: Checkout late (with overtime)**
  - Check in package booking
  - Preview checkout 3 hours late
  - Verify overtime calculated correctly
  - Apply overtime
  - Verify overtime added to bill
  - Pay and verify completed

- [ ] **Test 9: Waive overtime**
  - Check in package booking
  - Preview checkout late
  - Uncheck "Apply overtime"
  - Checkout without overtime
  - Verify no overtime charges added

- [ ] **Test 10: Checkout with outstanding balance**
  - Check in package booking
  - Attempt checkout with unpaid balance
  - Verify error message
  - Pay balance
  - Retry checkout → Should succeed

---

## SECTION 5: Error Handling

### Common Errors and Solutions

```typescript
const errorHandlers = {
  // Facility already released
  'Facility already released': (error) => {
    showInfoMessage('This facility has already been released.');
    refreshGuestEntry();
  },
  
  // Outstanding balance on checkout
  'outstanding balance': (error) => {
    showPaymentModal({
      balance: error.balance,
      message: 'Please collect payment before checkout'
    });
  },
  
  // Future checkout time
  'future date/time': (error) => {
    showErrorMessage('Cannot checkout with future date/time');
    resetCheckoutForm();
  },
  
  // Already checked out
  'already checked out': (error) => {
    showInfoMessage('This entry is already completed.');
    refreshGuestEntry();
  }
};
```

---

## SECTION 6: Quick Implementation Summary

### What Frontend MUST Do:

#### For ALL Entries:
1. ✅ Display entry type badge
2. ✅ Show payment status and balance
3. ✅ Link to billing for payments
4. ✅ Filter by Active/Completed status

#### For Walk-in & Swimming (Day-Use):
5. ✅ Show facility list with release status
6. ✅ Add "Release Facility" button per facility
7. ✅ Show auto-completion progress indicator
8. ✅ Refresh entry after facility release
9. ✅ NO manual checkout button

#### For Package (Overnight):
10. ✅ Show accommodation details
11. ✅ Add "Preview Checkout" button
12. ✅ Add "Checkout" button with overtime option
13. ✅ Show scheduled vs actual checkout time
14. ✅ NO facility release buttons
15. ✅ NO auto-completion indicator

---

## SECTION 7: State Flowchart

```
┌─────────────────────────────────────────────┐
│            GUEST ENTRY CREATED              │
│         (is_checked_out = false)            │
└──────────────────┬──────────────────────────┘
                   │
                   ↓
          ┌────────────────┐
          │   Entry Type?  │
          └────────┬───────┘
                   │
        ┌──────────┼──────────┐
        │          │          │
        ↓          ↓          ↓
   ┌────────┐ ┌────────┐ ┌────────┐
   │Walk-in │ │Swimming│ │Package │
   └───┬────┘ └───┬────┘ └───┬────┘
       │          │          │
       │  Day-Use │          │ Overnight
       └────┬─────┘          │
            │                │
            ↓                ↓
     ┌────────────┐   ┌────────────┐
     │ Release    │   │  Manual    │
     │ Facilities │   │  Checkout  │
     └─────┬──────┘   └─────┬──────┘
           │                │
           ↓                ↓
     ┌────────────┐   ┌────────────┐
     │ Pay Bill   │   │ Calculate  │
     └─────┬──────┘   │ Overtime   │
           │          └─────┬──────┘
           │                │
           ↓                ↓
     ┌────────────┐   ┌────────────┐
     │   AUTO-    │   │ Apply OT?  │
     │ COMPLETE   │   └─────┬──────┘
     └─────┬──────┘         │
           │                ↓
           │          ┌────────────┐
           │          │ Pay Final  │
           │          │    Bill    │
           │          └─────┬──────┘
           │                │
           └────────┬───────┘
                    │
                    ↓
         ┌──────────────────┐
         │    COMPLETED      │
         │ (is_checked_out = │
         │      true)        │
         └──────────────────┘
```

---

## SECTION 8: Frontend Code Snippets

### React Component Example

```typescript
// GuestEntryCard.tsx
import React from 'react';

interface GuestEntryCardProps {
  entry: GuestEntry;
  onReleaseFacility: (extensionId: number) => void;
  onCheckout: (entryId: number) => void;
  onPreviewCheckout: (entryId: number) => void;
}

export const GuestEntryCard: React.FC<GuestEntryCardProps> = ({
  entry,
  onReleaseFacility,
  onCheckout,
  onPreviewCheckout
}) => {
  const isDayUse = 
    entry.entry_type === 'Walk_In' ||
    entry.booking?.booking_type === 'Swimming';
  
  const isPackage = entry.booking?.booking_type === 'Package';
  
  // Auto-completion check for day-use
  const autoCompleteStatus = isDayUse ? {
    allReleased: entry.billing.extensions
      .filter(e => e.extension_type === 'facility')
      .every(e => e.is_released),
    fullyPaid: entry.billing.balance === 0,
    readyToComplete: false
  } : null;
  
  if (autoCompleteStatus) {
    autoCompleteStatus.readyToComplete = 
      autoCompleteStatus.allReleased && autoCompleteStatus.fullyPaid;
  }
  
  return (
    <div className="guest-entry-card">
      {/* Header */}
      <div className="card-header">
        <span className="entry-reference">{entry.entry_reference}</span>
        <EntryTypeBadge type={entry.entry_type} />
        {entry.booking && (
          <BookingTypeBadge type={entry.booking.booking_type} />
        )}
        <StatusBadge status={entry.is_checked_out ? 'Completed' : 'Active'} />
      </div>
      
      {/* Guest Info */}
      <div className="guest-info">
        <span>{entry.guest_name}</span>
        <span>{entry.number_of_guests} guests</span>
        <span>Check-in: {formatTime(entry.check_in_datetime)}</span>
      </div>
      
      {/* Facilities (Day-Use Only) */}
      {isDayUse && (
        <div className="facilities-section">
          <h4>Facilities ({
            entry.billing.extensions.filter(e => e.is_released).length
          }/{entry.billing.extensions.length} Released)</h4>
          {entry.billing.extensions
            .filter(e => e.extension_type === 'facility')
            .map(ext => (
              <FacilityItem
                key={ext.id}
                facility={ext}
                onRelease={() => onReleaseFacility(ext.id)}
              />
            ))}
        </div>
      )}
      
      {/* Accommodation (Package Only) */}
      {isPackage && (
        <div className="accommodation-section">
          <h4>Accommodation</h4>
          <div>{entry.facilities[0]?.facility.name}</div>
          <div>Scheduled: {formatTime(entry.booking.check_out_datetime)}</div>
        </div>
      )}
      
      {/* Payment */}
      <div className="payment-section">
        <div>Total: ₱{entry.billing.total_amount}</div>
        <div>Paid: ₱{entry.billing.amount_paid}</div>
        <div className={entry.billing.balance > 0 ? 'balance-due' : 'balance-paid'}>
          Balance: ₱{entry.billing.balance}
        </div>
      </div>
      
      {/* Auto-Complete Status (Day-Use Only) */}
      {isDayUse && autoCompleteStatus && !entry.is_checked_out && (
        <div className="auto-complete-status">
          {autoCompleteStatus.readyToComplete ? (
            <div className="ready">
              ✅ Ready to auto-complete
            </div>
          ) : (
            <div className="pending">
              <div className={autoCompleteStatus.fullyPaid ? 'done' : 'pending'}>
                {autoCompleteStatus.fullyPaid ? '✅' : '⏳'} Fully paid
              </div>
              <div className={autoCompleteStatus.allReleased ? 'done' : 'pending'}>
                {autoCompleteStatus.allReleased ? '✅' : '⏳'} All facilities released
              </div>
            </div>
          )}
        </div>
      )}
      
      {/* Actions */}
      <div className="actions">
        <button onClick={() => viewDetails(entry.id)}>
          View Details
        </button>
        
        {isPackage && !entry.is_checked_out && (
          <>
            <button onClick={() => onPreviewCheckout(entry.id)}>
              Preview Checkout
            </button>
            <button onClick={() => onCheckout(entry.id)}>
              Checkout
            </button>
          </>
        )}
        
        {entry.billing.balance > 0 && (
          <button onClick={() => collectPayment(entry.id)}>
            Collect Payment
          </button>
        )}
      </div>
    </div>
  );
};
```

---

## IMPLEMENTATION PRIORITY

### Phase 1: Core Display (Week 1)
1. ✅ List all guest entries with filters
2. ✅ Display entry type and status badges
3. ✅ Show payment information
4. ✅ Basic detail view

### Phase 2: Walk-in & Swimming (Week 2)
5. ✅ Facility release UI
6. ✅ Release facility API integration
7. ✅ Auto-completion status indicator
8. ✅ Real-time updates after release

### Phase 3: Package Checkout (Week 3)
9. ✅ Preview checkout modal
10. ✅ Overtime calculation display
11. ✅ Checkout with overtime option
12. ✅ Payment verification flow

### Phase 4: Polish & Testing (Week 4)
13. ✅ Error handling
14. ✅ Loading states
15. ✅ Success animations
16. ✅ End-to-end testing

---

**Need Help?**
- API Documentation: `GUEST_MONITORING_FRONTEND_GUIDE.md`
- Overtime Details: `OVERTIME_SYSTEM_IMPLEMENTATION.md`
- Unified Request Structure: `WALK_IN_BOOKING_UNIFICATION.md`
