# Facility Release System - Frontend Implementation Guide

## Overview

Walk-in guests and Swimming booking guests can leave at any time by releasing their facilities individually. Once all facilities are released and payment is complete, the guest entry automatically completes without formal checkout.

## Key Concepts

### Two Types of Facilities

1. **Initial Facilities** - Booked during walk-in creation or booking check-in
   - Stored in `guest_entry_facilities` table
   - Use `/release-initial-facility/{guestEntryFacilityId}` endpoint

2. **Extension Facilities** - Added mid-stay via billing extensions
   - Stored in `billing_extensions` table
   - Use `/release-facility/{extensionId}` endpoint

### Auto-Completion Logic

Guest entry auto-completes when:
- ✅ ALL initial facilities released
- ✅ ALL extension facilities released  
- ✅ Fully paid (balance = 0, payment_status = 'paid')

Only applies to day-use guests (walk-ins and Swimming bookings). Package bookings require formal checkout.

---

## API Endpoints

### 1. Release Initial Facility

**Endpoint:** `POST /api/guest-monitoring/{guestEntryId}/release-initial-facility/{guestEntryFacilityId}`

**When to use:** Release a facility that was booked during initial walk-in creation or booking check-in.

**Request:**
```typescript
interface ReleaseInitialFacilityRequest {
  notes?: string; // Optional release notes (max 500 chars)
}
```

**Example:**
```typescript
async function releaseInitialFacility(
  guestEntryId: number,
  guestEntryFacilityId: number,
  notes?: string
) {
  const response = await fetch(
    `/api/guest-monitoring/${guestEntryId}/release-initial-facility/${guestEntryFacilityId}`,
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({ notes }),
    }
  );

  return response.json();
}
```

**Success Response (200):**
```json
{
  "status": "success",
  "message": "Facility released successfully",
  "data": {
    "guest_entry_facility_id": 123,
    "facility": "Large Cottage #2",
    "released_at": "2025-11-19 14:30:00",
    "released_by": "Juan Dela Cruz"
  }
}
```

**Error Responses:**
- **400** - Facility already released
- **403** - Not allowed for package bookings
- **404** - Facility not found

---

### 2. Release Extension Facility

**Endpoint:** `POST /api/guest-monitoring/{guestEntryId}/release-facility/{extensionId}`

**When to use:** Release a facility that was added mid-stay via billing extension.

**Request:**
```typescript
interface ReleaseFacilityRequest {
  notes?: string; // Optional release notes (max 500 chars)
}
```

**Example:**
```typescript
async function releaseExtensionFacility(
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
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({ notes }),
    }
  );

  return response.json();
}
```

**Success Response (200):**
```json
{
  "status": "success",
  "message": "Facility released successfully",
  "data": {
    "extension_id": 456,
    "facility": "Small Cottage #5",
    "released_at": "2025-11-19 15:00:00",
    "released_by": "Maria Santos"
  }
}
```

---

### 3. Get Guest Entry with Facilities

**Endpoint:** `GET /api/guest-monitoring/{guestEntryId}`

**Initial Facilities Structure:**
```typescript
interface GuestEntryFacility {
  id: number;
  guest_entry_id: number;
  facility_id: number;
  facility: {
    id: number;
    name: string;
    facility_type: {
      id: number;
      name: string;
    };
  };
  rate_id: number;
  quantity: number;
  start_datetime: string | null;
  end_datetime: string | null;
  duration_hours: number;
  base_amount: number;
  extension_hours: number;
  extension_amount: number;
  subtotal: number;
  is_released: boolean;           // ✅ NEW
  released_at: string | null;     // ✅ NEW
  released_by: number | null;     // ✅ NEW
  released_by_user?: {            // ✅ NEW
    id: number;
    full_name: string;
  };
}
```

**Extension Facilities Structure:**
```typescript
interface BillingExtension {
  id: number;
  billing_id: number;
  extension_type: 'facility' | 'guest' | 'damage' | 'service';
  facility_id: number | null;
  facility?: {
    id: number;
    facility_name: string;
    facility_type: string;
  };
  rate_id: number | null;
  amount: number;
  quantity: number;
  total_amount: number;
  is_overtime: boolean;
  is_released: boolean;           // ✅ Facility release status
  released_at: string | null;
  released_by: number | null;
  released_by_user?: {
    id: number;
    full_name: string;
  };
}
```

---

## Frontend Implementation

### Step 1: Display Facility Release Status

Show release status for both initial and extension facilities:

```tsx
import React from 'react';

interface FacilityCardProps {
  facility: GuestEntryFacility | BillingExtension;
  type: 'initial' | 'extension';
  onRelease: () => void;
  canRelease: boolean;
}

function FacilityCard({ facility, type, onRelease, canRelease }: FacilityCardProps) {
  const facilityName = type === 'initial' 
    ? facility.facility?.name 
    : facility.facility?.facility_name;

  return (
    <div className={`facility-card ${facility.is_released ? 'released' : 'active'}`}>
      <div className="facility-header">
        <h4>{facilityName}</h4>
        {facility.is_released ? (
          <span className="badge badge-success">✅ Released</span>
        ) : (
          <span className="badge badge-warning">🔒 In Use</span>
        )}
      </div>

      <div className="facility-details">
        <p>Amount: ₱{facility.subtotal?.toFixed(2) || facility.total_amount?.toFixed(2)}</p>
        {facility.quantity && <p>Quantity: {facility.quantity}</p>}
      </div>

      {facility.is_released && facility.released_at && (
        <div className="release-info">
          <small>
            Released on {new Date(facility.released_at).toLocaleString()}
            {facility.released_by_user && ` by ${facility.released_by_user.full_name}`}
          </small>
        </div>
      )}

      {!facility.is_released && canRelease && (
        <button 
          onClick={onRelease} 
          className="btn btn-primary btn-sm"
        >
          Release Facility
        </button>
      )}
    </div>
  );
}
```

### Step 2: Implement Release Functions

```typescript
import { useState } from 'react';

function useGuestEntryFacilities(guestEntryId: number) {
  const [guestEntry, setGuestEntry] = useState<GuestEntry | null>(null);
  const [loading, setLoading] = useState(false);

  // Fetch guest entry with facilities
  const fetchGuestEntry = async () => {
    setLoading(true);
    try {
      const response = await fetch(`/api/guest-monitoring/${guestEntryId}`);
      const data = await response.json();
      setGuestEntry(data.data);
    } catch (error) {
      console.error('Failed to fetch guest entry:', error);
    } finally {
      setLoading(false);
    }
  };

  // Release initial facility
  const releaseInitialFacility = async (guestEntryFacilityId: number, notes?: string) => {
    try {
      const response = await fetch(
        `/api/guest-monitoring/${guestEntryId}/release-initial-facility/${guestEntryFacilityId}`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ notes }),
        }
      );

      const result = await response.json();

      if (result.status === 'success') {
        // Refresh guest entry to get updated status
        await fetchGuestEntry();
        
        // Check if auto-completed
        if (guestEntry?.is_checked_out) {
          alert('All facilities released and payment complete! Guest entry auto-completed.');
        } else {
          alert('Facility released successfully!');
        }
      } else {
        alert(result.message);
      }
    } catch (error) {
      console.error('Failed to release facility:', error);
      alert('Failed to release facility. Please try again.');
    }
  };

  // Release extension facility
  const releaseExtensionFacility = async (extensionId: number, notes?: string) => {
    try {
      const response = await fetch(
        `/api/guest-monitoring/${guestEntryId}/release-facility/${extensionId}`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ notes }),
        }
      );

      const result = await response.json();

      if (result.status === 'success') {
        await fetchGuestEntry();
        
        if (guestEntry?.is_checked_out) {
          alert('All facilities released and payment complete! Guest entry auto-completed.');
        } else {
          alert('Facility released successfully!');
        }
      } else {
        alert(result.message);
      }
    } catch (error) {
      console.error('Failed to release extension facility:', error);
      alert('Failed to release facility. Please try again.');
    }
  };

  return {
    guestEntry,
    loading,
    fetchGuestEntry,
    releaseInitialFacility,
    releaseExtensionFacility,
  };
}
```

### Step 3: Build Complete Facility Management UI

```tsx
function GuestEntryFacilities({ guestEntryId }: { guestEntryId: number }) {
  const {
    guestEntry,
    loading,
    fetchGuestEntry,
    releaseInitialFacility,
    releaseExtensionFacility,
  } = useGuestEntryFacilities(guestEntryId);

  useEffect(() => {
    fetchGuestEntry();
  }, [guestEntryId]);

  if (loading || !guestEntry) return <div>Loading...</div>;

  // Check if guest can release facilities (day-use only)
  const isDayUse = guestEntry.entry_type === 'walk_in' || 
                   guestEntry.booking?.booking_type === 'Swimming';

  // Calculate release progress
  const initialFacilities = guestEntry.facilities || [];
  const extensionFacilities = guestEntry.billing?.extensions?.filter(
    (ext) => ext.extension_type === 'facility'
  ) || [];

  const totalFacilities = initialFacilities.length + extensionFacilities.length;
  const releasedFacilities = [
    ...initialFacilities.filter(f => f.is_released),
    ...extensionFacilities.filter(f => f.is_released),
  ].length;

  const allReleased = totalFacilities > 0 && releasedFacilities === totalFacilities;
  const isFullyPaid = guestEntry.billing?.balance <= 0 && 
                      guestEntry.billing?.payment_status === 'paid';

  return (
    <div className="facility-management">
      <h3>Facility Management</h3>

      {/* Release Progress */}
      <div className="release-progress">
        <div className="progress-header">
          <span>Released: {releasedFacilities} / {totalFacilities}</span>
          {allReleased && <span className="badge badge-success">All Released ✅</span>}
        </div>
        <div className="progress-bar">
          <div 
            className="progress-fill" 
            style={{ width: `${(releasedFacilities / totalFacilities) * 100}%` }}
          />
        </div>
      </div>

      {/* Payment Status */}
      <div className="payment-status">
        <span>Payment Status: </span>
        {isFullyPaid ? (
          <span className="badge badge-success">Fully Paid ✅</span>
        ) : (
          <span className="badge badge-warning">
            Balance: ₱{guestEntry.billing?.balance?.toFixed(2)}
          </span>
        )}
      </div>

      {/* Auto-Complete Status */}
      {allReleased && isFullyPaid && !guestEntry.is_checked_out && (
        <div className="alert alert-info">
          🎉 All conditions met! Entry will auto-complete.
        </div>
      )}

      {guestEntry.is_checked_out && (
        <div className="alert alert-success">
          ✅ Guest entry completed on {new Date(guestEntry.checkout_datetime).toLocaleString()}
        </div>
      )}

      {/* Initial Facilities */}
      {initialFacilities.length > 0 && (
        <div className="facilities-section">
          <h4>Initial Facilities</h4>
          <div className="facilities-grid">
            {initialFacilities.map((facility) => (
              <FacilityCard
                key={facility.id}
                facility={facility}
                type="initial"
                canRelease={isDayUse && !guestEntry.is_checked_out}
                onRelease={() => releaseInitialFacility(facility.id)}
              />
            ))}
          </div>
        </div>
      )}

      {/* Extension Facilities */}
      {extensionFacilities.length > 0 && (
        <div className="facilities-section">
          <h4>Extension Facilities</h4>
          <div className="facilities-grid">
            {extensionFacilities.map((extension) => (
              <FacilityCard
                key={extension.id}
                facility={extension}
                type="extension"
                canRelease={isDayUse && !guestEntry.is_checked_out}
                onRelease={() => releaseExtensionFacility(extension.id)}
              />
            ))}
          </div>
        </div>
      )}

      {!isDayUse && (
        <div className="alert alert-info">
          ℹ️ Package bookings require formal checkout. Facility release not available.
        </div>
      )}
    </div>
  );
}
```

---

## Business Rules

### When to Show Release Button

✅ **SHOW** release button when:
- Guest entry type is `walk_in` OR booking type is `Swimming`
- Guest entry is NOT checked out (`is_checked_out = false`)
- Facility is NOT already released (`is_released = false`)

❌ **HIDE** release button when:
- Booking type is `Package` (requires formal checkout)
- Guest entry is already checked out
- Facility is already released

### Auto-Completion Flow

```
1. User releases facility (initial or extension)
   ↓
2. Backend marks facility as released
   ↓
3. Backend checks auto-completion conditions:
   - All initial facilities released?
   - All extension facilities released?
   - Fully paid?
   ↓
4. If YES to all → Auto-complete:
   - Set is_checked_out = true
   - Set checkout_datetime = now
   - Set billing_status = 'completed'
   - Update booking status (if Swimming)
   ↓
5. Frontend refreshes data
   ↓
6. Show completion message to user
```

---

## Error Handling

```typescript
async function handleFacilityRelease(releaseFunction: () => Promise<any>) {
  try {
    const result = await releaseFunction();
    
    if (result.status === 'success') {
      toast.success(result.message);
      
      // Refresh data to check for auto-completion
      await fetchGuestEntry();
      
      // Check if auto-completed
      if (guestEntry.is_checked_out) {
        toast.success('🎉 All facilities released! Guest entry auto-completed.');
      }
    } else {
      toast.error(result.message);
    }
  } catch (error) {
    if (error.response?.status === 400) {
      toast.warning('Facility already released');
    } else if (error.response?.status === 403) {
      toast.error('Facility release not allowed for package bookings');
    } else if (error.response?.status === 404) {
      toast.error('Facility not found');
    } else {
      toast.error('Failed to release facility. Please try again.');
    }
  }
}
```

---

## Testing Checklist

### Walk-in Guest with Initial Facilities

- [ ] Create walk-in entry with 2 cottages
- [ ] Pay full amount
- [ ] Release first cottage → Should remain active
- [ ] Release second cottage → Should auto-complete
- [ ] Verify `is_checked_out = true` and `billing_status = 'completed'`

### Walk-in Guest with Mixed Facilities

- [ ] Create walk-in entry with 1 cottage
- [ ] Add extension facility via billing
- [ ] Pay full amount
- [ ] Release initial cottage → Should remain active
- [ ] Release extension facility → Should auto-complete

### Walk-in Guest Unpaid

- [ ] Create walk-in entry with cottages
- [ ] Release all facilities (don't pay)
- [ ] Verify stays active (not auto-completed)
- [ ] Make payment → Should auto-complete

### Package Booking

- [ ] Create package booking and check in
- [ ] Verify release buttons are hidden
- [ ] Attempt API call → Should return 403 error

### Swimming Booking

- [ ] Create Swimming booking and check in
- [ ] Pay and release facilities → Should auto-complete
- [ ] Verify booking status changes to `Checked_Out`

---

## Visual States

### Facility Card States

```css
.facility-card {
  border: 2px solid #e0e0e0;
  border-radius: 8px;
  padding: 16px;
  margin-bottom: 12px;
}

.facility-card.active {
  border-color: #ff9800;
  background: #fff3e0;
}

.facility-card.released {
  border-color: #4caf50;
  background: #e8f5e9;
  opacity: 0.8;
}

.release-info {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid #e0e0e0;
  color: #666;
}
```

### Progress Bar

```css
.progress-bar {
  width: 100%;
  height: 24px;
  background: #e0e0e0;
  border-radius: 12px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #4caf50, #8bc34a);
  transition: width 0.3s ease;
}
```

---

## Summary

**Key Points:**
1. Two separate endpoints for initial vs extension facilities
2. Auto-completion when ALL facilities released + fully paid
3. Only for day-use guests (walk-ins and Swimming bookings)
4. Package bookings blocked from facility release
5. Frontend must refresh after release to check auto-completion status

**Data Flow:**
- Initial facilities → `guest_entry.facilities[]` → `/release-initial-facility/{id}`
- Extension facilities → `billing.extensions[]` → `/release-facility/{id}`
- Both checked in auto-completion logic
