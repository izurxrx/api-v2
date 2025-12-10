# Overdue Billing Automation Guide

## ✅ Overview

This system automatically marks billings as **overdue** when they pass their `due_date` without being fully paid. No notifications are sent - it's a silent status update for tracking purposes.

---

## 🎯 What Gets Marked as Overdue?

The system checks billings that meet ALL these criteria:

1. **Has a due date** (`due_date IS NOT NULL`)
2. **Past the due date** (`due_date < NOW()`)
3. **Not fully paid** (`payment_status IN ('pending', 'partial')`)

---

## 🔄 How It Works

### Automatic Scheduling

The command runs **every hour** via Laravel's task scheduler:

```php
// routes/console.php
Schedule::command('billings:mark-overdue')->hourly();
```

### Command Details

**Command Signature:** `billings:mark-overdue`

**What it does:**
1. Queries all billings past their due date
2. Updates `payment_status` from `pending`/`partial` to `overdue`
3. Logs each update with billing details
4. Outputs summary of marked billings

---

## 📋 Status Flow

```
Booking/Entry Created
    ↓
Billing Created (payment_status = 'pending')
    ↓
Due Date Set (if applicable)
    ↓
┌─────────────────────────────────────┐
│ Guest makes payment before due date │
│ → payment_status = 'paid'           │ ✅ Done
│ → Never becomes overdue             │
└─────────────────────────────────────┘
    OR
┌─────────────────────────────────────┐
│ Guest makes partial payment         │
│ → payment_status = 'partial'        │
│ → Due date passes                   │ ⏰
│ → Auto-marked as 'overdue' (daily)  │
└─────────────────────────────────────┘
    OR
┌─────────────────────────────────────┐
│ Guest makes NO payment              │
│ → payment_status = 'pending'        │
│ → Due date passes                   │ ⏰
│ → Auto-marked as 'overdue' (daily)  │
└─────────────────────────────────────┘
```

---

## 🧪 Testing

### Manual Testing

Run the command manually to test:

```bash
php artisan billings:mark-overdue
```

**Expected Output:**
```
✓ Billing #123 (Booking BK-2025-001) marked as overdue
✓ Billing #124 (GuestEntry GE-2025-002) marked as overdue
Completed: 2 billing(s) marked as overdue
```

### Database Verification

Check which billings should be marked as overdue:

```sql
SELECT
    b.id,
    b.billable_type,
    b.billable_id,
    b.total_amount,
    b.balance,
    b.payment_status,
    b.due_date,
    DATEDIFF(NOW(), b.due_date) as days_overdue
FROM billings b
WHERE b.due_date IS NOT NULL
  AND b.due_date < NOW()
  AND b.payment_status IN ('pending', 'partial')
  AND b.deleted_at IS NULL
ORDER BY b.due_date ASC;
```

### Verify After Running Command

```sql
SELECT
    b.id,
    b.billable_type,
    b.billable_id,
    b.payment_status,
    b.due_date,
    b.updated_at
FROM billings b
WHERE b.payment_status = 'overdue'
  AND b.deleted_at IS NULL
ORDER BY b.updated_at DESC;
```

---

## 📊 Real-World Examples

### Example 1: Booking with Missed Payment

```
Booking Created: Nov 20, 2025
Total: ₱10,000
Downpayment Paid: ₱5,000 (Nov 20)
Balance: ₱5,000
Due Date: Nov 22, 2025 (check-in date)

Timeline:
- Nov 20: Booking created, downpayment paid
  → payment_status = 'partial'

- Nov 22: Check-in date arrives, balance NOT paid
  → Still payment_status = 'partial'

- Nov 23 01:00 AM: Scheduler runs (next hour)
  → Auto-marked as 'overdue'
  → payment_status = 'overdue'
```

### Example 2: Walk-in Entry with Credit

```
Walk-in Entry: Nov 24, 10:00 AM
Total: ₱2,500
Payment: ₱0 (guest requests to pay later)
Due Date: Nov 24, 6:00 PM (same day)

Timeline:
- Nov 24 10:00 AM: Entry created
  → payment_status = 'pending'

- Nov 24 6:00 PM: Due date passes, no payment
  → Still payment_status = 'pending'

- Nov 24 7:00 PM: Scheduler runs (next hour)
  → Auto-marked as 'overdue'
  → payment_status = 'overdue'
```

### Example 3: Paid Before Due Date (No Overdue)

```
Booking Created: Nov 20, 2025
Total: ₱10,000
Downpayment Paid: ₱5,000 (Nov 20)
Balance: ₱5,000
Due Date: Nov 22, 2025

Timeline:
- Nov 20: Booking created, downpayment paid
  → payment_status = 'partial'

- Nov 21: Guest pays remaining balance
  → payment_status = 'paid'

- Nov 23 01:00 AM: Scheduler runs
  → Billing is 'paid', NOT overdue
  → No action taken ✅
```

---

## 📝 Logs

Every update is logged for audit trail:

### Laravel Log (storage/logs/laravel.log)

```
[2025-11-24 00:00:15] local.INFO: Billing marked as overdue {
    "billing_id": 123,
    "billable_type": "Booking",
    "billable_id": 45,
    "reference": "BK-2025-001",
    "due_date": "2025-11-22 12:00:00",
    "total_amount": 10000,
    "balance": 5000,
    "marked_at": "2025-11-24 00:00:15"
}

[2025-11-24 00:00:15] local.INFO: Overdue billing check completed {
    "billings_marked": 2,
    "checked_at": "2025-11-24 00:00:15"
}
```

---

## 🎨 Frontend Integration

### API Endpoint

Query overdue billings using the existing endpoint:

```
GET /api/billings?overdue=true
```

**Response:**
```json
{
  "status": "success",
  "data": [
    {
      "id": 123,
      "billable_type": "App\\Models\\Booking",
      "billable_id": 45,
      "total_amount": 10000,
      "balance": 5000,
      "payment_status": "overdue",
      "due_date": "2025-11-22T12:00:00.000000Z",
      "days_overdue": 2
    }
  ]
}
```

### Display Overdue Billings

```jsx
// React Example
const OverdueBillings = () => {
  const [overdueBillings, setOverdueBillings] = useState([]);

  useEffect(() => {
    fetch('/api/billings?overdue=true', {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json',
      }
    })
    .then(res => res.json())
    .then(data => setOverdueBillings(data.data));
  }, []);

  return (
    <div>
      <h2>Overdue Billings ({overdueBillings.length})</h2>
      {overdueBillings.map(billing => (
        <div key={billing.id} className="overdue-card">
          <span className="badge-overdue">OVERDUE</span>
          <p>Reference: {billing.booking?.booking_reference || billing.guest_entry?.entry_reference}</p>
          <p>Balance: ₱{billing.balance.toLocaleString()}</p>
          <p>Due: {new Date(billing.due_date).toLocaleDateString()}</p>
          <p className="text-danger">
            {billing.days_overdue} day(s) overdue
          </p>
        </div>
      ))}
    </div>
  );
};
```

---

## 🔧 Scheduler Setup

### Verify Scheduler is Running

Laravel's scheduler requires a cron job to be set up on your server:

**For Linux/Mac:**
```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

**For Windows (Task Scheduler):**
1. Open Task Scheduler
2. Create Basic Task
3. Trigger: Daily at 00:00
4. Action: Start a program
5. Program: `php`
6. Arguments: `artisan schedule:run`
7. Start in: `C:\path\to\your\api`

### Test Scheduler Locally

Run the scheduler manually:

```bash
php artisan schedule:run
```

**Expected Output:**
```
Running scheduled command: php artisan billings:mark-overdue
```

---

## 🎯 Business Rules

### What Happens to Overdue Billings?

1. **Status Change**: `payment_status` updated to 'overdue'
2. **Balance Remains**: Outstanding balance is NOT modified
3. **Billing Still Active**: Can still accept payments
4. **No Notifications**: Silent status update (as requested)

### Payment After Overdue

When guest pays an overdue billing:

```
Overdue Billing: ₱5,000 balance

Guest pays ₱5,000 → payment_status = 'paid' ✅
Guest pays ₱2,500 → payment_status = 'overdue' (still partial)
Guest pays ₱5,000+ → payment_status = 'paid' ✅
```

The payment logic automatically updates status from 'overdue' to 'paid' when balance reaches zero.

---

## 🔍 Comparison with No-Show

| Feature | No-Show | Overdue |
|---------|---------|---------|
| **Target** | Bookings | Billings |
| **Checks** | `booking_status = 'Confirmed'` | `payment_status IN ('pending', 'partial')` |
| **Trigger** | 4 hours after check-in time | Past due_date |
| **Frequency** | Every hour | Every hour |
| **Updates** | `booking_status = 'No_Show'` | `payment_status = 'overdue'` |
| **Adds Notes** | Yes (appends to notes field) | No |

---

## 📋 Troubleshooting

### Billings Not Being Marked

**Issue:** Billings past due date but still showing as 'pending'

**Check:**
1. Is the scheduler running?
   ```bash
   php artisan schedule:run
   ```

2. Is the command registered in routes/console.php?
   ```php
   Schedule::command('billings:mark-overdue')->daily();
   ```

3. Do the billings have a due_date set?
   ```sql
   SELECT id, due_date, payment_status FROM billings WHERE id = 123;
   ```

### Scheduler Not Running

**Issue:** Commands not executing automatically

**Solutions:**
- **Local Development**: Run manually or use:
  ```bash
  php artisan schedule:work
  ```

- **Production**: Verify cron job is set up correctly:
  ```bash
  crontab -l
  ```

### Check Recent Updates

```sql
SELECT
    b.id,
    b.payment_status,
    b.due_date,
    b.updated_at,
    TIMESTAMPDIFF(MINUTE, b.due_date, b.updated_at) as minutes_to_mark
FROM billings b
WHERE b.payment_status = 'overdue'
  AND DATE(b.updated_at) = CURDATE()
ORDER BY b.updated_at DESC;
```

---

## ✅ Implementation Checklist

- [x] Created `MarkOverdueBillings` command
- [x] Added command signature: `billings:mark-overdue`
- [x] Query billings with `due_date < now()` and status `pending`/`partial`
- [x] Update `payment_status` to 'overdue'
- [x] Log all updates for audit trail
- [x] Scheduled command to run daily at midnight
- [x] Excluded notification functionality (as requested)
- [ ] Set up server cron job (production deployment)
- [ ] Test command manually
- [ ] Verify logs are being written
- [ ] Update frontend to display overdue billings

---

## 🎯 Summary

**Automatic Overdue Marking:**
- ✅ Runs every hour
- ✅ Marks unpaid/partial billings past due date
- ✅ Updates payment_status to 'overdue'
- ✅ Logs all changes for audit trail
- ✅ No notifications sent (as requested)

**Manual Testing:**
```bash
php artisan billings:mark-overdue
```

**Query Overdue Billings:**
```
GET /api/billings?overdue=true
```

---

**Status:** ✅ Implemented and scheduled
**Last Updated:** November 24, 2025
**Automatic overdue billing tracking is ready!**
