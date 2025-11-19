# Guest Monitoring - Quick Visual Reference

**For:** Frontend Developers  
**Purpose:** At-a-glance implementation guide

---

## 🎯 Three Entry Types = Three Different Workflows

```
┌─────────────────────────────────────────────────────────────┐
│  ENTRY TYPE          │  COMPLETION  │  UI BUTTON             │
├──────────────────────┼──────────────┼────────────────────────┤
│  🔵 Walk-in          │  🤖 AUTO     │  [Release Facility]    │
│  🟢 Swimming Booking │  🤖 AUTO     │  [Release Facility]    │
│  🟣 Package Booking  │  👤 MANUAL   │  [Checkout]            │
└─────────────────────────────────────────────────────────────┘
```

---

## ✅ Implementation Decision Tree

```
                    Guest Entry
                        │
                        ↓
            ┌───────────┴───────────┐
            │   Check entry_type    │
            └───────────┬───────────┘
                        │
        ┌───────────────┼───────────────┐
        │               │               │
        ↓               ↓               ↓
    Walk_In         Booking         Booking
                        │               │
                        ↓               ↓
                Check booking_type      │
                        │               │
                ┌───────┴───────┐       │
                │               │       │
                ↓               ↓       │
            Swimming        Package     │
                │               │       │
                ↓               ↓       ↓
        ┌───────────────┐  ┌───────────────┐
        │  DAY-USE      │  │  OVERNIGHT    │
        │               │  │               │
        │  Show:        │  │  Show:        │
        │  • Facilities │  │  • Accom.     │
        │  • Release ✓  │  │  • Checkout ✓ │
        │  • Auto ✓     │  │  • Overtime ✓ │
        └───────────────┘  └───────────────┘
```

---

## 🔵 Walk-in Entry - UI Mockup

```
╔════════════════════════════════════════════════╗
║ SWIM-001 [Walk_In] [Active]                   ║
║ John Doe | 5 guests | 09:00 AM                ║
╠════════════════════════════════════════════════╣
║ 📦 FACILITIES (2/3 Released)                  ║
║ ✅ Cottage #3  Released 2:00 PM               ║
║ ✅ Cottage #5  Released 2:15 PM               ║
║ ❌ Cottage #2  [Release] ← CLICK THIS         ║
╠════════════════════════════════════════════════╣
║ 💰 PAYMENT                                     ║
║ Total: ₱2,500 | Paid: ₱2,500 | Balance: ₱0   ║
╠════════════════════════════════════════════════╣
║ ⏳ AUTO-COMPLETION                             ║
║ ✅ Fully paid                                  ║
║ ⏳ Waiting for 1 facility                      ║
║ → Will complete when all released              ║
╠════════════════════════════════════════════════╣
║ [View Details] [View Receipt]                 ║
╚════════════════════════════════════════════════╝

API CALL:
POST /api/guest-monitoring/1/release-facility/456
{ "notes": "Cottage returned in good condition" }

RESULT:
→ Facility marked released
→ Check if auto-complete: paid ✅ + all released ✅
→ If yes: is_checked_out = true, status = Completed
```

---

## 🟢 Swimming Booking - UI Mockup

```
╔════════════════════════════════════════════════╗
║ SWIM-005 [Booking] [Active]                   ║
║ Jane Smith | 8 guests | 10:30 AM              ║
║ 📋 Booking: BOOK-20251118-012 (Swimming)     ║
╠════════════════════════════════════════════════╣
║ 📦 FACILITIES (All Released ✅)               ║
║ ✅ Cottage #1  Released 3:00 PM               ║
║ ✅ Cottage #3  Released 3:05 PM               ║
╠════════════════════════════════════════════════╣
║ 💰 PAYMENT                                     ║
║ Total: ₱3,200 | Paid: ₱3,200 | Balance: ₱0   ║
╠════════════════════════════════════════════════╣
║ ✅ READY TO AUTO-COMPLETE                      ║
║ ✅ All facilities released                     ║
║ ✅ Fully paid                                  ║
║ → System will complete automatically           ║
╠════════════════════════════════════════════════╣
║ [View Details] [View Receipt]                 ║
╚════════════════════════════════════════════════╝

SAME AS WALK-IN:
→ Release facilities
→ Auto-complete when paid + released
→ PLUS: Updates booking status to 'Checked_Out'
```

---

## 🟣 Package Booking - UI Mockup

```
╔════════════════════════════════════════════════╗
║ SWIM-010 [Booking] [Package] [Active]         ║
║ Bob Johnson | 4 guests | Yesterday 2:00 PM    ║
║ 📋 Booking: BOOK-20251118-008                 ║
╠════════════════════════════════════════════════╣
║ 🏠 ACCOMMODATION                               ║
║ Villa #2                                       ║
║ Scheduled: Today 12:00 PM                      ║
║ Current: 3:30 PM ⚠️ 3h 30m late               ║
╠════════════════════════════════════════════════╣
║ 💰 PAYMENT                                     ║
║ Total: ₱8,500 | Paid: ₱5,000 | Balance: ₱3,500║
╠════════════════════════════════════════════════╣
║ ⚠️ MANUAL CHECKOUT REQUIRED                    ║
║ → Staff must click checkout                    ║
║ → Overtime can be optionally applied           ║
╠════════════════════════════════════════════════╣
║ [Preview Checkout] [Checkout] [Collect $]     ║
╚════════════════════════════════════════════════╝

STEP 1: Click [Preview Checkout]
→ Shows overtime calculation modal

STEP 2: Overtime Modal:
╔════════════════════════════════════════════════╗
║ 📊 CHECKOUT PREVIEW                            ║
╠════════════════════════════════════════════════╣
║ Scheduled: 12:00 PM                            ║
║ Actual:    3:30 PM                             ║
║ Late by:   3h 30m                              ║
║                                                ║
║ Overtime: 3.25 hrs × ₱450 = ₱1,462.50         ║
║                                                ║
║ Current:  ₱3,500.00                            ║
║ +Overtime: ₱1,462.50                           ║
║ Total:    ₱4,962.50                            ║
║                                                ║
║ ☐ Apply overtime charges ← STAFF DECIDES      ║
╠════════════════════════════════════════════════╣
║ [Cancel] [Proceed to Checkout]                ║
╚════════════════════════════════════════════════╝

STEP 3: Checkout
POST /api/guest-monitoring/10/checkout
{
  "exit_date": "2025-11-20",
  "exit_time": "15:30",
  "apply_overtime": true  ← CHECKBOX VALUE
}

RESULT:
→ If balance > 0: Show payment required
→ If balance = 0: Complete checkout
```

---

## 🎨 Status Badge Guide

```css
/* Entry Type Badges */
[Walk_In]    = Blue   (#DBEAFE text: #1E40AF)
[Booking]    = Green  (#D1FAE5 text: #065F46)

/* Booking Type Badges */
[Swimming]   = Teal   (#CCFBF1 text: #115E59)
[Package]    = Purple (#E9D5FF text: #6B21A8)

/* Status Badges */
[Active]     = Yellow (#FEF3C7 text: #92400E)
[Completed]  = Green  (#D1FAE5 text: #065F46)
[Pending]    = Red    (#FEE2E2 text: #991B1B)

/* Icons */
✅ = Released / Completed / True
❌ = Not released / False
⏳ = Pending / In progress
⚠️ = Warning / Attention needed
```

---

## 📋 API Endpoint Cheat Sheet

```
┌─────────────────────────────────────────────────────┐
│  ACTION                │  ENDPOINT                   │
├────────────────────────┼─────────────────────────────┤
│  List all entries      │  GET /api/guest-monitoring  │
│  Create walk-in        │  POST /api/guest-monitoring │
│  Check-in booking      │  POST .../check-in-booking/ │
│  Release facility      │  POST .../release-facility/ │
│  Preview checkout      │  POST .../preview-checkout  │
│  Checkout              │  POST .../checkout          │
│  View details          │  GET .../guest-monitoring/  │
└─────────────────────────────────────────────────────┘
```

---

## 🔄 Auto-Completion Logic (Day-Use)

```javascript
// Pseudocode
function checkAutoComplete(entry) {
  const isDayUse = 
    entry.entry_type === 'Walk_In' ||
    entry.booking?.booking_type === 'Swimming';
  
  if (!isDayUse) return false;
  
  const allFacilitiesReleased = 
    entry.billing.extensions
      .filter(e => e.extension_type === 'facility')
      .every(e => e.is_released);
  
  const fullyPaid = 
    entry.billing.balance === 0 &&
    entry.billing.payment_status === 'paid';
  
  return allFacilitiesReleased && fullyPaid;
}

// If true:
//   → Backend sets is_checked_out = true
//   → Entry moves to Completed list
//   → Show success message
```

---

## ⚡ Quick Validation Checklist

### Before Going Live:

**Walk-in:**
- [ ] Shows facility list with ✅/❌ status
- [ ] Release button appears for unreleased facilities
- [ ] Auto-completion indicator shows progress
- [ ] NO checkout button visible
- [ ] Entry auto-completes when conditions met

**Swimming:**
- [ ] Same as walk-in PLUS
- [ ] Shows booking reference
- [ ] Links to original booking
- [ ] Updates booking status on completion

**Package:**
- [ ] Shows accommodation (not facility list)
- [ ] Preview Checkout button visible
- [ ] Checkout button visible
- [ ] Overtime checkbox in preview modal
- [ ] NO release facility buttons
- [ ] NO auto-completion indicator
- [ ] Prevents checkout if balance > 0

---

## 🚨 Common Mistakes to Avoid

```
❌ DON'T: Show checkout button for walk-ins
✅ DO:    Show release facility buttons

❌ DON'T: Show facility release for packages
✅ DO:    Show checkout button

❌ DON'T: Auto-complete packages
✅ DO:    Require manual checkout

❌ DON'T: Force overtime charges
✅ DO:    Make it optional (checkbox)

❌ DON'T: Allow checkout with balance > 0
✅ DO:    Verify payment first

❌ DON'T: Forget to refresh after release
✅ DO:    Check for auto-completion
```

---

## 📞 Need Help?

**Full Documentation:**
- `GUEST_MONITORING_IMPLEMENTATION_CHECKLIST.md` - Complete guide
- `GUEST_MONITORING_FRONTEND_GUIDE.md` - API reference
- `OVERTIME_SYSTEM_IMPLEMENTATION.md` - Overtime details

**Quick Tests:**
1. Walk-in: Create → Pay → Release → ✅ Auto-complete
2. Swimming: Check-in → Pay → Release → ✅ Auto-complete
3. Package: Check-in → [Checkout] → Pay → ✅ Complete

**Remember:**
- 🔵 Walk-in = Auto
- 🟢 Swimming = Auto
- 🟣 Package = Manual
