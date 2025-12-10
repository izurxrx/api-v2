# Frontend Quick Reference: Booking Overlap Feature

## 🎯 TL;DR

When creating a booking, if another booking is within 2 hours, you'll get a **422 error**. Show a modal asking the manager for their password + reason to proceed.

---

## 📋 Checklist

- [ ] Catch 422 errors with `warning_type: "booking_proximity_conflict"`
- [ ] Show modal with conflict details
- [ ] Check user role (Manager/Admin only)
- [ ] Show password + reason input fields
- [ ] Validate: password not empty, reason ≥ 10 characters
- [ ] Resubmit with `manager_override` object
- [ ] Handle success (201) and errors (401, 403, 422)
- [ ] Clear password field after submission
- [ ] Log success message

---

## 💻 Minimal Code Example

```typescript
async function createBooking(formData, withOverride = false) {
  const payload = { ...formData };

  if (withOverride) {
    payload.manager_override = {
      password: managerPassword,
      reason: overrideReason
    };
  }

  try {
    const response = await api.post('/api/bookings', payload);
    // Success! (201)
    alert('Booking created!');
  } catch (error) {
    if (error.response?.status === 422 &&
        error.response?.data?.warning_type === 'booking_proximity_conflict') {
      // Show override modal
      showModal(error.response.data.conflicts);
    } else if (error.response?.status === 401) {
      alert('Wrong password');
    } else if (error.response?.status === 403) {
      alert('Only managers can override');
    }
  }
}
```

---

## 🔴 What You'll Receive (422 Response)

```json
{
  "status": "warning",
  "warning_type": "booking_proximity_conflict",
  "conflicts": [
    {
      "booking_reference": "BK-20251205-001",
      "guest_name": "Jane Smith",
      "gap_hours": 1.0,
      "severity": "critical",
      "message": "Booking BK-20251205-001 ends at 2:00 PM, only 1.0 hours before..."
    }
  ]
}
```

---

## 🟢 What You'll Send Back

```json
{
  "booking_type": "Package",
  "guest_name": "John Doe",
  "// ... all original fields ...": "...",
  "manager_override": {
    "password": "manager_password_here",
    "reason": "Customer emergency requiring quick turnaround"
  }
}
```

---

## 🎨 UI Elements Needed

### Modal Structure
```
┌─────────────────────────────────────────┐
│ ⚠️ Booking Proximity Warning            │
├─────────────────────────────────────────┤
│                                         │
│ 🔴 Booking BK-001 ends at 2:00 PM      │
│    Only 1.0 hours before new booking    │
│                                         │
│ ┌─ Manager Override ──────────────────┐│
│ │                                      ││
│ │ Password: [________________]         ││
│ │                                      ││
│ │ Reason: [_________________________] ││
│ │         [_________________________] ││
│ │                                      ││
│ │  [Cancel]  [Override & Continue]    ││
│ └──────────────────────────────────────┘│
└─────────────────────────────────────────┘
```

### Fields
- **Password input**: `type="password"`, `autocomplete="current-password"`
- **Reason textarea**: `minlength="10"`, `rows="3"`
- **Buttons**: Disable during loading, enable only when valid

---

## ✅ Validation Rules

| Field | Rule | Error Message |
|-------|------|---------------|
| Password | Required, not empty | "Please enter your password" |
| Reason | Required, ≥ 10 chars | "Reason must be at least 10 characters" |
| User Role | Must be Manager/Admin | "Only managers can override" (backend) |

---

## 🎨 Severity Colors

```css
.severity-critical { background: #fee; border-color: #dc3545; } /* Red */
.severity-high     { background: #fff3cd; border-color: #ffc107; } /* Orange */
.severity-medium   { background: #d1ecf1; border-color: #17a2b8; } /* Blue */
.severity-low      { background: #d4edda; border-color: #28a745; } /* Green */
```

---

## 🔐 Security Reminders

1. **Clear password** after submission (success or failure)
2. **Use HTTPS** for all API calls
3. **Hide override button** for non-managers
4. **Never log passwords** in console/analytics
5. **Validate on submit** before API call

```javascript
// Example: Clear password
const closeModal = () => {
  setPassword('');      // ✅ Clear
  setReason('');        // ✅ Clear
  setShowModal(false);
};
```

---

## 🐛 Common Errors

### 401 - Wrong Password
```json
{ "message": "Incorrect password. Please verify your credentials." }
```
**Fix:** Show error message, allow retry

### 403 - Not Authorized
```json
{ "message": "Only Managers or Admins can override..." }
```
**Fix:** Hide override option, show "Contact manager" message

### 422 - Validation Error
```json
{ "message": "Override reason must be at least 10 characters long." }
```
**Fix:** Show error near reason field

---

## 📱 Responsive Design Tips

- **Mobile**: Stack fields vertically
- **Desktop**: Show side-by-side
- **Max height**: `90vh` with scroll for long conflict lists
- **Touch targets**: Buttons ≥ 44px height
- **Font size**: ≥ 14px for inputs on mobile

---

## 🧪 Test Cases

| Test | Expected Result |
|------|----------------|
| Submit without override | Shows modal with conflicts |
| Non-manager sees modal | Shows "contact manager" message |
| Manager enters wrong password | 401 error, shows message |
| Manager enters < 10 char reason | Shows validation error |
| Manager enters valid credentials | 201 success, booking created |
| Close modal | Clears password & reason fields |

---

## 📞 Need Help?

- **Full guide:** [FRONTEND_BOOKING_OVERLAP_IMPLEMENTATION.md](./FRONTEND_BOOKING_OVERLAP_IMPLEMENTATION.md)
- **API testing:** [BOOKING_OVERLAP_TESTING.md](./BOOKING_OVERLAP_TESTING.md)
- **Overview:** [BOOKING_OVERLAP_SUMMARY.md](./BOOKING_OVERLAP_SUMMARY.md)

---

## 🎯 Success Flow

```
1. User submits booking → 2. Get 422 with conflicts → 3. Show modal
                                                            ↓
                                               4. Manager enters password + reason
                                                            ↓
                                               5. Resubmit with override data
                                                            ↓
                                               6. Get 201 success → Booking created!
```

---

**Last Updated:** November 24, 2025
**Status:** Ready for frontend integration
