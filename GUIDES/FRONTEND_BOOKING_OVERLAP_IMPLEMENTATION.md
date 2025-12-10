# Frontend Implementation Guide: Booking Overlap Warning & Manager Override

## Overview

This guide explains how to implement the booking proximity warning and manager override feature on the frontend. When creating a booking, the backend checks if there are existing bookings within 2 hours before or after the new booking time, and requires manager approval to proceed.

---

## 🔄 Complete User Flow

```
1. User fills out booking form → Clicks "Create Booking"
2. Frontend submits booking data to API
3. Backend detects conflict (booking too close) → Returns 422 error
4. Frontend shows warning modal with conflict details
5. If user is Manager/Admin → Shows "Override" button with password field
6. Manager enters password + reason → Resubmits with override data
7. Backend validates manager credentials → Creates booking if valid
8. Frontend shows success message
```

---

## 📡 API Response Formats

### 1️⃣ Initial Booking Request (No Override)

**Endpoint:** `POST /api/bookings`

**Request Body:**
```json
{
  "booking_type": "Package",
  "guest_name": "John Doe",
  "contact_number": "09171234567",
  "check_in_date": "2025-11-25",
  "check_in_time": "14:00",
  "check_out_date": "2025-11-26",
  "check_out_time": "12:00",
  "number_of_guests": 5,
  "facilities": [
    {
      "facility_id": 1,
      "rate_id": 10,
      "quantity": 1,
      "rate_amount": 5000
    }
  ]
}
```

### 2️⃣ Conflict Detected Response

**Status:** `422 Unprocessable Entity`

**Response Body:**
```json
{
  "status": "warning",
  "message": "Booking conflicts detected. Manager override required.",
  "warning_type": "booking_proximity_conflict",
  "conflict_count": 1,
  "requires_manager_override": true,
  "conflicts": [
    {
      "type": "proximity_before",
      "booking_id": 123,
      "booking_reference": "BK-20251124-001",
      "guest_name": "Jane Smith",
      "booking_ends_at": "2025-11-25T13:00:00+08:00",
      "new_booking_starts_at": "2025-11-25T14:00:00+08:00",
      "gap_minutes": 60,
      "gap_hours": 1.0,
      "severity": "critical",
      "message": "Booking BK-20251124-001 (Guest: Jane Smith) ends at Nov 25, 2025 01:00 PM, only 1.0 hours before the new booking starts"
    }
  ],
  "override_instructions": {
    "message": "To proceed with this booking, a manager must provide their password and reason for override.",
    "required_fields": {
      "manager_override.password": "Manager's password",
      "manager_override.reason": "Reason for overriding the proximity warning"
    }
  }
}
```

### 3️⃣ Manager Override Request

**Endpoint:** `POST /api/bookings` (same endpoint, with override data)

**Request Body:**
```json
{
  "booking_type": "Package",
  "guest_name": "John Doe",
  "contact_number": "09171234567",
  "check_in_date": "2025-11-25",
  "check_in_time": "14:00",
  "check_out_date": "2025-11-26",
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
  "manager_override": {
    "password": "manager_password_here",
    "reason": "Customer has urgent need for quick turnaround"
  }
}
```

### 4️⃣ Success Response (After Override)

**Status:** `201 Created`

**Response Body:**
```json
{
  "status": "success",
  "message": "Booking created successfully",
  "data": {
    "id": 456,
    "booking_reference": "BK-20251125-002",
    "booking_status": "Pending",
    // ... full booking data
  }
}
```

### 5️⃣ Error Responses

#### Invalid Password
**Status:** `401 Unauthorized`
```json
{
  "message": "Incorrect password. Please verify your credentials."
}
```

#### Not a Manager
**Status:** `403 Forbidden`
```json
{
  "message": "Only Managers or Admins can override booking proximity warnings."
}
```

#### Missing Reason
**Status:** `422 Unprocessable Entity`
```json
{
  "message": "A reason is required to override booking conflicts."
}
```

---

## 💻 Frontend Implementation Examples

### React/TypeScript Example

```typescript
import { useState } from 'react';
import axios from 'axios';

interface BookingFormData {
  booking_type: string;
  guest_name: string;
  contact_number: string;
  check_in_date: string;
  check_in_time: string;
  check_out_date: string;
  check_out_time: string;
  number_of_guests: number;
  facilities: Array<{
    facility_id: number;
    rate_id: number;
    quantity: number;
    rate_amount: number;
  }>;
}

interface Conflict {
  type: string;
  booking_reference: string;
  guest_name: string;
  gap_hours: number;
  severity: string;
  message: string;
}

interface OverrideData {
  password: string;
  reason: string;
}

function BookingForm() {
  const [formData, setFormData] = useState<BookingFormData>({...});
  const [conflicts, setConflicts] = useState<Conflict[]>([]);
  const [showOverrideModal, setShowOverrideModal] = useState(false);
  const [overridePassword, setOverridePassword] = useState('');
  const [overrideReason, setOverrideReason] = useState('');
  const [loading, setLoading] = useState(false);

  // Get current user from auth context
  const { user } = useAuth();
  const isManager = user?.roles?.includes('Manager') || user?.roles?.includes('Admin');

  const createBooking = async (withOverride: boolean = false) => {
    setLoading(true);

    try {
      const payload: any = { ...formData };

      // Add manager override if needed
      if (withOverride) {
        payload.manager_override = {
          password: overridePassword,
          reason: overrideReason
        };
      }

      const response = await axios.post('/api/bookings', payload);

      // Success!
      alert('Booking created successfully!');
      setShowOverrideModal(false);
      // Redirect or refresh...

    } catch (error: any) {
      if (error.response?.status === 422 &&
          error.response?.data?.warning_type === 'booking_proximity_conflict') {

        // Conflict detected - show warning modal
        setConflicts(error.response.data.conflicts);
        setShowOverrideModal(true);

      } else if (error.response?.status === 401) {
        // Wrong password
        alert(error.response.data.message);

      } else if (error.response?.status === 403) {
        // Not authorized
        alert(error.response.data.message);

      } else {
        // Other errors
        alert('Error creating booking: ' + error.response?.data?.message);
      }
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    createBooking(false); // Initial attempt without override
  };

  const handleOverride = () => {
    // Validate inputs
    if (!overridePassword) {
      alert('Please enter your password');
      return;
    }
    if (!overrideReason || overrideReason.length < 10) {
      alert('Please provide a reason (at least 10 characters)');
      return;
    }

    createBooking(true); // Retry with override
  };

  return (
    <>
      <form onSubmit={handleSubmit}>
        {/* Your booking form fields */}
        <button type="submit" disabled={loading}>
          {loading ? 'Creating...' : 'Create Booking'}
        </button>
      </form>

      {/* Override Modal */}
      {showOverrideModal && (
        <div className="modal-overlay">
          <div className="modal-content">
            <h2>⚠️ Booking Proximity Warning</h2>

            <div className="conflicts-list">
              <p>The following conflicts were detected:</p>
              {conflicts.map((conflict, index) => (
                <div
                  key={index}
                  className={`conflict-item severity-${conflict.severity}`}
                >
                  <span className="severity-badge">{conflict.severity}</span>
                  <p>{conflict.message}</p>
                  <small>
                    Booking Reference: {conflict.booking_reference} |
                    Gap: {conflict.gap_hours} hours
                  </small>
                </div>
              ))}
            </div>

            {isManager ? (
              <>
                <div className="override-section">
                  <h3>Manager Override</h3>
                  <p className="warning-text">
                    As a manager, you can override this warning. Please provide
                    your password and a reason for proceeding.
                  </p>

                  <div className="form-group">
                    <label>Your Password *</label>
                    <input
                      type="password"
                      value={overridePassword}
                      onChange={(e) => setOverridePassword(e.target.value)}
                      placeholder="Enter your password"
                      autoComplete="current-password"
                    />
                  </div>

                  <div className="form-group">
                    <label>Reason for Override * (min 10 characters)</label>
                    <textarea
                      value={overrideReason}
                      onChange={(e) => setOverrideReason(e.target.value)}
                      placeholder="e.g., Customer has urgent need for quick turnaround"
                      rows={3}
                      minLength={10}
                    />
                    <small>{overrideReason.length}/10 characters</small>
                  </div>
                </div>

                <div className="modal-actions">
                  <button
                    onClick={() => setShowOverrideModal(false)}
                    disabled={loading}
                  >
                    Cancel
                  </button>
                  <button
                    onClick={handleOverride}
                    disabled={loading || !overridePassword || overrideReason.length < 10}
                    className="btn-danger"
                  >
                    {loading ? 'Processing...' : 'Override & Continue'}
                  </button>
                </div>
              </>
            ) : (
              <div className="non-manager-message">
                <p>⛔ Only managers can override this warning.</p>
                <p>Please contact a manager to proceed with this booking.</p>
                <button onClick={() => setShowOverrideModal(false)}>
                  Close
                </button>
              </div>
            )}
          </div>
        </div>
      )}
    </>
  );
}
```

### Vue 3 Example

```vue
<template>
  <div>
    <form @submit.prevent="createBooking(false)">
      <!-- Your booking form fields -->
      <button type="submit" :disabled="loading">
        {{ loading ? 'Creating...' : 'Create Booking' }}
      </button>
    </form>

    <!-- Override Modal -->
    <div v-if="showOverrideModal" class="modal-overlay">
      <div class="modal-content">
        <h2>⚠️ Booking Proximity Warning</h2>

        <div class="conflicts-list">
          <p>The following conflicts were detected:</p>
          <div
            v-for="(conflict, index) in conflicts"
            :key="index"
            :class="`conflict-item severity-${conflict.severity}`"
          >
            <span class="severity-badge">{{ conflict.severity }}</span>
            <p>{{ conflict.message }}</p>
            <small>
              Booking Reference: {{ conflict.booking_reference }} |
              Gap: {{ conflict.gap_hours }} hours
            </small>
          </div>
        </div>

        <div v-if="isManager" class="override-section">
          <h3>Manager Override</h3>
          <p class="warning-text">
            As a manager, you can override this warning. Please provide
            your password and a reason for proceeding.
          </p>

          <div class="form-group">
            <label>Your Password *</label>
            <input
              v-model="overridePassword"
              type="password"
              placeholder="Enter your password"
              autocomplete="current-password"
            />
          </div>

          <div class="form-group">
            <label>Reason for Override * (min 10 characters)</label>
            <textarea
              v-model="overrideReason"
              placeholder="e.g., Customer has urgent need for quick turnaround"
              rows="3"
              minlength="10"
            />
            <small>{{ overrideReason.length }}/10 characters</small>
          </div>

          <div class="modal-actions">
            <button @click="showOverrideModal = false" :disabled="loading">
              Cancel
            </button>
            <button
              @click="createBooking(true)"
              :disabled="loading || !overridePassword || overrideReason.length < 10"
              class="btn-danger"
            >
              {{ loading ? 'Processing...' : 'Override & Continue' }}
            </button>
          </div>
        </div>

        <div v-else class="non-manager-message">
          <p>⛔ Only managers can override this warning.</p>
          <p>Please contact a manager to proceed with this booking.</p>
          <button @click="showOverrideModal = false">Close</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import axios from 'axios';
import { useAuthStore } from '@/stores/auth';

const authStore = useAuthStore();
const isManager = computed(() =>
  authStore.user?.roles?.includes('Manager') ||
  authStore.user?.roles?.includes('Admin')
);

const formData = ref({
  // ... booking form fields
});

const conflicts = ref([]);
const showOverrideModal = ref(false);
const overridePassword = ref('');
const overrideReason = ref('');
const loading = ref(false);

const createBooking = async (withOverride = false) => {
  loading.value = true;

  try {
    const payload = { ...formData.value };

    if (withOverride) {
      payload.manager_override = {
        password: overridePassword.value,
        reason: overrideReason.value
      };
    }

    const response = await axios.post('/api/bookings', payload);

    // Success!
    alert('Booking created successfully!');
    showOverrideModal.value = false;
    // Redirect or refresh...

  } catch (error) {
    if (error.response?.status === 422 &&
        error.response?.data?.warning_type === 'booking_proximity_conflict') {

      conflicts.value = error.response.data.conflicts;
      showOverrideModal.value = true;

    } else if (error.response?.status === 401) {
      alert(error.response.data.message);

    } else if (error.response?.status === 403) {
      alert(error.response.data.message);

    } else {
      alert('Error creating booking: ' + error.response?.data?.message);
    }
  } finally {
    loading.value = false;
  }
};
</script>
```

---

## 🎨 Suggested CSS Styling

```css
/* Modal Overlay */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: white;
  padding: 2rem;
  border-radius: 8px;
  max-width: 600px;
  width: 90%;
  max-height: 90vh;
  overflow-y: auto;
}

/* Conflicts List */
.conflicts-list {
  margin: 1.5rem 0;
  padding: 1rem;
  background: #fff3cd;
  border-left: 4px solid #ffc107;
  border-radius: 4px;
}

.conflict-item {
  padding: 1rem;
  margin: 0.5rem 0;
  border-radius: 4px;
  border-left: 4px solid;
}

.conflict-item.severity-critical {
  background: #fee;
  border-color: #dc3545;
}

.conflict-item.severity-high {
  background: #fff3cd;
  border-color: #ffc107;
}

.conflict-item.severity-medium {
  background: #d1ecf1;
  border-color: #17a2b8;
}

.conflict-item.severity-low {
  background: #d4edda;
  border-color: #28a745;
}

.severity-badge {
  display: inline-block;
  padding: 0.25rem 0.5rem;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: bold;
  text-transform: uppercase;
  margin-bottom: 0.5rem;
}

.severity-critical .severity-badge {
  background: #dc3545;
  color: white;
}

.severity-high .severity-badge {
  background: #ffc107;
  color: #000;
}

/* Override Section */
.override-section {
  margin-top: 1.5rem;
  padding: 1.5rem;
  background: #f8f9fa;
  border-radius: 4px;
}

.warning-text {
  color: #856404;
  background: #fff3cd;
  padding: 0.75rem;
  border-radius: 4px;
  margin-bottom: 1rem;
}

.form-group {
  margin-bottom: 1rem;
}

.form-group label {
  display: block;
  font-weight: 600;
  margin-bottom: 0.5rem;
}

.form-group input,
.form-group textarea {
  width: 100%;
  padding: 0.5rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 1rem;
}

.form-group small {
  display: block;
  margin-top: 0.25rem;
  color: #6c757d;
}

/* Modal Actions */
.modal-actions {
  display: flex;
  gap: 1rem;
  margin-top: 1.5rem;
  justify-content: flex-end;
}

.modal-actions button {
  padding: 0.5rem 1.5rem;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 1rem;
}

.modal-actions button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-danger {
  background: #dc3545;
  color: white;
}

.btn-danger:hover:not(:disabled) {
  background: #c82333;
}

/* Non-manager Message */
.non-manager-message {
  text-align: center;
  padding: 2rem;
  background: #f8d7da;
  border-radius: 4px;
  margin-top: 1rem;
}

.non-manager-message p {
  margin: 0.5rem 0;
  color: #721c24;
}
```

---

## 🔐 Security Considerations

### Frontend
1. **Never store passwords** - Clear the password field immediately after submission
2. **Hide override option** for non-managers using role-based rendering
3. **Use HTTPS** for all API calls to encrypt password transmission
4. **Clear sensitive data** when modal is closed
5. **Validate inputs** before sending to backend

### Example: Clearing Sensitive Data
```typescript
const closeModal = () => {
  setShowOverrideModal(false);
  setOverridePassword('');  // ✅ Clear password
  setOverrideReason('');    // ✅ Clear reason
  setConflicts([]);
};
```

---

## ✅ Testing Checklist

### Functional Tests
- [ ] Warning modal appears when booking conflict detected
- [ ] Modal shows all conflict details correctly
- [ ] Non-managers see "Contact Manager" message
- [ ] Managers see password and reason fields
- [ ] Password validation works (incorrect password shows error)
- [ ] Reason validation works (min 10 characters)
- [ ] Override succeeds with valid credentials
- [ ] Success message appears after override
- [ ] Modal closes after successful override
- [ ] Password field is cleared after submission

### Edge Cases
- [ ] Multiple conflicts display correctly
- [ ] Different severity levels styled appropriately
- [ ] Long guest names don't break layout
- [ ] Network errors handled gracefully
- [ ] Backend returns 403 → Frontend shows appropriate message
- [ ] Backend returns 401 → Frontend shows "wrong password" message

### UI/UX
- [ ] Modal is responsive on mobile devices
- [ ] Tab order is logical (password → reason → buttons)
- [ ] Enter key submits override
- [ ] ESC key closes modal
- [ ] Loading states prevent double submissions
- [ ] Password field uses type="password"
- [ ] Autocomplete attributes set correctly

---

## 📊 Conflict Severity Levels

The backend returns severity levels to help you style the warnings:

| Severity | Gap Time | Color Suggestion | Icon |
|----------|----------|------------------|------|
| `critical` | < 1 hour | Red (#dc3545) | 🔴 |
| `high` | 1-1.5 hours | Orange (#ffc107) | 🟠 |
| `medium` | 1.5-2 hours | Blue (#17a2b8) | 🔵 |
| `low` | 2+ hours | Green (#28a745) | 🟢 |

---

## 🐛 Common Issues & Solutions

### Issue: Password field autocomplete interferes
**Solution:** Use `autocomplete="current-password"` attribute

### Issue: Modal doesn't scroll on mobile
**Solution:** Add `max-height: 90vh; overflow-y: auto;` to modal-content

### Issue: User submits multiple times
**Solution:** Disable button during loading state

### Issue: Sensitive data persists after modal close
**Solution:** Clear all form fields when modal closes

### Issue: Timezone issues with dates
**Solution:** Backend returns ISO 8601 format, use proper date parsing

---

## 📝 Summary

1. **Initial Request** → Backend checks proximity → Returns 422 if conflict
2. **Show Modal** → Display conflicts with severity styling
3. **Manager Only** → Check user role before showing override option
4. **Validate Inputs** → Password + Reason (min 10 chars) required
5. **Resubmit** → Same endpoint with `manager_override` object
6. **Handle Response** → Success (201) or Error (401/403/422)
7. **Clear Data** → Remove password/reason after submission
8. **Audit Trail** → Backend automatically logs all override attempts

---

## 🎯 Quick Reference: Required Fields

```typescript
interface ManagerOverride {
  password: string;      // Manager's current password (REQUIRED)
  reason: string;        // Min 10 characters (REQUIRED)
}
```

**Validation Rules:**
- Password must match current user's password
- User must have "Manager" or "Admin" role
- Reason must be at least 10 characters
- Both fields are required

---

## 📞 Need Help?

If you encounter issues:
1. Check browser console for detailed error messages
2. Verify API response format matches this guide
3. Ensure user has correct role (Manager/Admin)
4. Check network tab for request/response payload
5. Review backend logs for detailed validation errors

---

**Last Updated:** November 24, 2025
**Backend Version:** Laravel 10 + Sanctum Authentication
**Frontend Compatibility:** React, Vue, Angular, Vanilla JS
