# Edit & Extension Complete Guide

**For Frontend Implementation**  
**Date:** November 19, 2025  
**Status:** Based on actual backend code

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Edit Booking Rules](#edit-booking-rules)
3. [Edit Walk-in Rules](#edit-walk-in-rules)
4. [Extensions (All Types)](#extensions-all-types)
5. [API Reference](#api-reference)
6. [Validation Rules](#validation-rules)
7. [Frontend Implementation](#frontend-implementation)
8. [Testing Checklist](#testing-checklist)

---

## 🎯 Overview

### Edit vs Extension

| Feature | Edit (PUT) | Extension (POST) |
|---------|-----------|------------------|
| **Purpose** | Modify original booking/entry | Add charges mid-stay |
| **When Available** | Pending status only | Pending/Confirmed/Active |
| **What Changes** | All details (dates, facilities, etc.) | Only adds new items |
| **Original Data** | Replaced | Preserved |
| **Payment Impact** | Recalculates balance | Adds to balance |

### Your Clarifications

**✅ YES - Edit uses same fields as creation**
- Same form structure
- Same validation rules
- Same data structure
- Just modifying existing record

**✅ YES - Extensions use creation logic**
- Select facilities → Same facility picker
- Select rates → Same rate picker
- Select discounts → Same discount logic
- Just adding to existing billing

---

## 🔄 Edit Booking Rules

### **Status-Based Edit Permissions**

```
┌─────────────────────────────────────────────────────┐
│ BOOKING STATUS        │ EDIT ALLOWED                │
├───────────────────────┼─────────────────────────────┤
│ Pending               │ ✅ FULL EDIT                │
│ Confirmed             │ ⚠️  CONTACT ONLY           │
│ Checked_In            │ ❌ NO EDIT (Use Extensions) │
│ Checked_Out           │ ❌ NO EDIT                  │
│ Cancelled             │ ❌ NO EDIT                  │
│ No_Show               │ ❌ NO EDIT                  │
└─────────────────────────────────────────────────────┘
```

### **1. Pending Bookings - Full Edit**

**Endpoint:** `PUT /api/booking/{id}`

**ALL fields editable (same as creation):**

```json
{
  "booking_type": "Swimming|Package",
  "entrance_rate_id": 1,
  "guest_name": "Updated Name",
  "contact_number": "09171234567",
  "email": "email@example.com",
  "number_of_guests": 6,
  "check_in_date": "2025-11-25",
  "check_out_date": "2025-11-25",
  "check_in_time": "14:00",
  "check_out_time": "18:00",
  
  "facilities": [
    {
      "facility_id": 1,
      "rate_id": 1,
      "quantity": 2,
      "rate_amount": 1500.00
    }
  ],
  
  "discount_mode": "Direct|Seasonal|Manual|None",
  "discount_id": 1,
  "manual_discount_amount": 500.00,
  
  "guest_discounts": [
    {
      "guest_type": "senior",
      "count": 2,
      "discount_id": 3
    }
  ],
  
  "third_party_services": [
    {
      "service_name": "Videography",
      "amount": 5000.00
    }
  ],
  
  "special_requests": "Notes here",
  "notes": "Internal notes"
}
```

**What Happens:**
1. ✅ Deletes old facilities
2. ✅ Creates new facilities
3. ✅ Recalculates all totals
4. ✅ Updates billing balance
5. ✅ Shows old vs new total in response

**Response:**
```json
{
  "status": "success",
  "message": "Booking updated successfully",
  "data": { },
  "changes": {
    "old_total": 5000.00,
    "new_total": 6500.00,
    "difference": 1500.00,
    "new_balance": 3500.00
  }
}
```

---

### **2. Confirmed Bookings - Contact Only**

**Endpoint:** `PUT /api/booking/{id}`

**ONLY these fields editable:**

```json
{
  "guest_name": "Updated Name",
  "contact_number": "09171234567",
  "special_requests": "Updated notes"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Booking contact details updated. Use billing extensions to add facilities, guests, or services.",
  "data": { },
  "hint": "To modify facilities, guests, or amounts, use: POST /api/billings/{billing_id}/extensions"
}
```

---

### **3. Checked-In/Checked-Out Bookings - No Edit**

**Response (422 Error):**
```json
{
  "status": "error",
  "message": "Cannot edit bookings that are already checked-in or checked-out"
}
```

---

## 🚶 Edit Walk-in Rules

### **Walk-in Edit Status**

```
┌─────────────────────────────────────────────────────┐
│ WALK-IN STATUS        │ EDIT ALLOWED                │
├───────────────────────┼─────────────────────────────┤
│ Active (not checked)  │ ❌ NO EDIT (Use Extensions) │
│ Checked Out           │ ❌ NO EDIT                  │
└─────────────────────────────────────────────────────┘
```

**Endpoint:** `PUT /api/guest-monitoring/{id}`

**Response (403 Error):**
```json
{
  "status": "error",
  "message": "Walk-in entries cannot be edited. Use billing extensions to add facilities, guests, or services.",
  "hint": "POST /api/billings/{billing_id}/extensions"
}
```

### **Why Walk-ins Can't Be Edited?**

1. **Immediate payment** - Already paid, transaction complete
2. **Real-time entry** - Guest already using facilities
3. **No booking phase** - No pending/confirmed status
4. **Use extensions instead** - Add charges as needed

---

## ➕ Extensions (All Types)

### **Extension Availability**

```
┌──────────────────────────────────────────────────────────┐
│ BILLING STATUS    │ EXTENSION ALLOWED                    │
├───────────────────┼──────────────────────────────────────┤
│ pending           │ ✅ YES                               │
│ confirmed         │ ✅ YES                               │
│ active            │ ✅ YES                               │
│ completed         │ ❌ NO (Transaction closed)           │
│ voided            │ ❌ NO (Cancelled)                    │
└──────────────────────────────────────────────────────────┘
```

### **Who Can Use Extensions?**

| Entry Type | Can Use Extensions |
|------------|-------------------|
| **Swimming Booking** | ✅ YES (Pending/Confirmed/Checked_In) |
| **Package Booking** | ✅ YES (Pending/Confirmed/Checked_In) |
| **Walk-in Entry** | ✅ YES (Active) |

### **Extension Types**

1. **facility** - Additional facilities (cottages, rooms, equipment)
2. **guest** - Extra guests beyond booking capacity
3. **damage** - Damage charges (broken items, lost equipment)
4. **service** - Third-party services (videography, catering, decorations)

---

### **Extension Request Structure**

**Endpoint:** `POST /api/billings/{billing_id}/add-extension`

**✅ TWO MODES SUPPORTED:**

**1. Smart Mode (Recommended)** - Reuses booking creation logic:
```json
{
  "facilities": [
    {
      "facility_id": 5,
      "rate_id": 12,
      "quantity": 1,
      "hours": 4  // Optional: for hourly rates
    }
  ],
  "guest_charges": [
    {
      "guest_type": "senior",
      "count": 2,
      "rate_per_guest": 350.00,
      "discount_id": 8
    }
  ],
  "third_party_services": [
    {
      "service_name": "Videography",
      "amount": 2400.00
    }
  ],
  "discount_mode": "Direct",
  "discount_id": null,
  "manual_discount_amount": 0,
  "payment_required": true,
  "payment_amount": 1500.00,
  "payment_method": "Cash"
}
```

**2. Simple Mode (Backward Compatible)** - For damage/service:
```json
{
  "extension_type": "damage",
  "description": "Broken window",
  "amount": 2500.00,
  "quantity": 1,
  "metadata": {},
  "payment_required": false
}
```

---

### **1. Facility Extension**

**Example: Adding a cottage mid-stay (Smart Mode)**

```json
{
  "facilities": [
    {
      "facility_id": 5,
      "rate_id": 12,
      "quantity": 1,
      "hours": null  // For day-based rates
    }
  ]
}
```

**Example: Adding extension hours (Hourly Rate)**

```json
{
  "facilities": [
    {
      "facility_id": 5,
      "rate_id": 15,  // Extension Rate - Per Hour
      "quantity": 1,
      "hours": 4  // 4 hours extension
    }
  ]
}
```

**Backend Calculation:**
- If `hours` provided: `rate.extension_fee × hours × quantity`
- If no `hours`: `rate.base_price × quantity`
- Description auto-generated: "Cottage B - Extension Rate (4.0 hours × 1)"

**Frontend Flow:**
1. User clicks "Add Facility"
2. **Same facility picker as creation** ✅
3. Select facility → Loads available rates
4. Select rate → Auto-fills amount from `rate.base_price` or `rate.extension_fee`
5. If rate has `extension_fee`, show "Hours" input
6. Enter quantity
7. Submit → Backend calculates total automatically ✅

---

### **2. Guest Extension**

**Example: Adding guests with discount (Smart Mode)**

```json
{
  "guest_charges": [
    {
      "guest_type": "senior",
      "count": 2,
      "rate_per_guest": 350.00,
      "discount_id": 8  // Senior Citizen 20% discount
    },
    {
      "guest_type": "adult",
      "count": 1,
      "rate_per_guest": 350.00,
      "discount_id": null
    }
  ],
  "discount_mode": "Direct"
}
```

**Backend Calculation:**
- Base amount: `rate_per_guest × count`
- Applies discount per guest if `discount_id` provided
- If discount is Percentage: `(rate_per_guest × discount.value / 100) × count`
- If discount is Fixed: `min(discount.value, rate_per_guest) × count`
- Final: `base_amount - discount_amount`

**Frontend Flow:**
1. User clicks "Add Guests"
2. Enter guest type (adult/senior/child)
3. **Same discount picker as creation** ✅
4. Select discount (optional) → Backend applies automatically
5. Enter count
6. Enter rate per guest
7. Submit → Backend calculates discounted total ✅

---

### **3. Damage Extension**

**Example: Broken window (Simple Mode)**

```json
{
  "extension_type": "damage",
  "description": "Broken cottage window",
  "amount": 2500.00,
  "quantity": 1,
  "metadata": {
    "item_damaged": "Window",
    "location": "Cottage A",
    "incident_date": "2025-11-19",
    "responsible_party": "Guest admission"
  }
}
```

**Backend Calculation:**
- Total: `amount × quantity`
- No discount application
- Simple pass-through

**Frontend Flow:**
1. User clicks "Add Damage"
2. Enter description
3. Enter cost (manual)
4. Enter quantity (usually 1)
5. Optional: Add damage details in metadata
6. Submit → Backend creates extension record ✅

---

### **4. Service Extension**

**Example: Videography service (Smart Mode)**

```json
{
  "third_party_services": [
    {
      "service_name": "Videography service - 3 hours",
      "amount": 2400.00
    }
  ]
}
```

**OR Simple Mode:**

```json
{
  "extension_type": "service",
  "description": "Videography service - 3 hours",
  "amount": 800.00,
  "quantity": 3,
  "metadata": {
    "service_provider": "XYZ Productions",
    "time_slot": "14:00-17:00",
    "service_type": "Videography"
  }
}
```

**Backend Calculation:**
- Smart Mode: Direct amount (total already calculated)
- Simple Mode: `amount × quantity`

**Frontend Flow:**
1. User clicks "Add Service"
2. **Same third-party service logic as creation** ✅
3. Enter service name
4. Enter total amount (Smart Mode) OR amount per hour + quantity (Simple Mode)
5. Optional: Provider details in metadata
6. Submit → Backend creates extension record ✅

---

### **Extension with Immediate Payment**

**Request:**
```json
{
  "extension_type": "facility",
  "description": "Additional Cottage B",
  "amount": 1500.00,
  "quantity": 1,
  
  "payment_required": true,
  "payment_amount": 1500.00,
  "payment_method": "Cash"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Extension added successfully",
  "data": {
    "extension": {
      "id": 789,
      "billing_id": 456,
      "extension_type": "facility",
      "description": "Additional Cottage B",
      "amount": 1500.00,
      "quantity": 1,
      "total_amount": 1500.00,
      "added_by": 18,
      "created_at": "2025-11-19T22:17:10Z"
    },
    "billing": {
      "id": 456,
      "total_amount": 7500.00,
      "balance": 3500.00,
      "amount_paid": 4000.00
    },
    "payment": {
      "id": 321,
      "amount": 1500.00,
      "payment_method": "Cash"
    }
  }
}
```

---

## 📡 API Reference

### **Edit Endpoints**

#### 1. Edit Booking (Pending)
```
PUT /api/booking/{id}
Authorization: Bearer {token}
Content-Type: application/json

Body: Same structure as POST /api/booking (creation)
```

**Response (200 Success):**
```json
{
  "status": "success",
  "message": "Booking updated successfully",
  "data": { },
  "changes": {
    "old_total": 5000.00,
    "new_total": 6500.00,
    "difference": 1500.00,
    "new_balance": 3500.00
  }
}
```

#### 2. Edit Booking (Confirmed - Contact Only)
```
PUT /api/booking/{id}
Authorization: Bearer {token}
Content-Type: application/json

Body: {
  "guest_name": "string",
  "contact_number": "string",
  "special_requests": "string"
}
```

#### 3. Edit Walk-in
```
PUT /api/guest-monitoring/{id}
Authorization: Bearer {token}

Response: 403 - Not allowed, use extensions
```

---

### **Extension Endpoint**

#### Add Extension (All Types)
```
POST /api/billings/{billing_id}/add-extension
Authorization: Bearer {token}
Content-Type: application/json

Body: {
  "extension_type": "facility|guest|damage|service",
  "description": "string (required)",
  "amount": number (required),
  "quantity": integer (required),
  "metadata": object (optional),
  "payment_required": boolean (optional),
  "payment_amount": number (optional),
  "payment_method": "string (optional)"
}
```

**Response (200 Success):**
```json
{
  "status": "success",
  "message": "Extension added successfully",
  "data": {
    "extension": { },
    "billing": { },
    "payment": { }
  }
}
```

**Response (422 Error - Wrong Status):**
```json
{
  "status": "error",
  "message": "Extensions can only be added to pending, confirmed, or active billings. Current status: completed"
}
```

---

## ✅ Validation Rules

### **Edit Booking - Pending Status**

| Field | Rule | Example |
|-------|------|---------|
| `booking_type` | required, in:Swimming,Package | "Swimming" |
| `entrance_rate_id` | required_if:booking_type,Swimming | 1 |
| `guest_name` | required, string, min:2 | "John Doe" |
| `contact_number` | required, string | "09171234567" |
| `email` | nullable, email | "john@example.com" |
| `number_of_guests` | required, integer, min:1 | 6 |
| `check_in_date` | required, date, format:Y-m-d | "2025-11-25" |
| `check_out_date` | required, date, after_or_equal:check_in_date | "2025-11-25" |
| `facilities` | required, array, min:1 | [...] |
| `facilities.*.facility_id` | required, exists:facilities | 1 |
| `facilities.*.rate_id` | required, exists:rates | 1 |
| `facilities.*.quantity` | required, integer, min:1 | 2 |
| `facilities.*.rate_amount` | required, numeric, min:0 | 1500.00 |
| `discount_mode` | nullable, in:None,Direct,Seasonal,Manual | "Direct" |
| `discount_id` | nullable, exists:discounts | 1 |
| `manual_discount_amount` | nullable, numeric, min:0 | 500.00 |

### **Edit Booking - Confirmed Status**

| Field | Rule |
|-------|------|
| `guest_name` | required, string, min:2 |
| `contact_number` | required, string |
| `special_requests` | nullable, string, max:1000 |

### **Extension Validation**

| Field | Rule | Notes |
|-------|------|-------|
| `extension_type` | required, in:facility,guest,damage,service | Choose type |
| `description` | required, string, max:255 | Clear description |
| `amount` | required, numeric, min:0 | Price per unit |
| `quantity` | required, integer, min:1 | Number of units |
| `metadata` | nullable, array | Optional extra data |
| `payment_required` | boolean | Default: false |
| `payment_amount` | nullable, numeric, min:0 | If paying now |
| `payment_method` | nullable, string | If payment_amount provided |

---

## 🎨 Frontend Implementation

### **1. Check Edit Permissions**

```javascript
function canEditBooking(booking) {
  const status = booking.booking_status;
  
  return {
    canFullEdit: status === 'Pending',
    canContactEdit: status === 'Confirmed',
    cannotEdit: ['Checked_In', 'Checked_Out', 'Cancelled'].includes(status),
    useExtensions: ['Checked_In'].includes(status)
  };
}

function canEditWalkIn(guestEntry) {
  // Walk-ins can NEVER be edited
  return {
    canEdit: false,
    useExtensions: true,
    message: 'Walk-in entries cannot be edited. Use billing extensions to add charges.'
  };
}

function canAddExtension(billing) {
  const status = billing.billing_status;
  return ['pending', 'confirmed', 'active'].includes(status);
}
```

---

### **2. Edit Booking Component**

```jsx
import React, { useState } from 'react';
import axios from 'axios';

function EditBookingForm({ booking, onSuccess }) {
  const [formData, setFormData] = useState({
    // Pre-populate with current booking data
    booking_type: booking.booking_type,
    entrance_rate_id: booking.entrance_rate_id,
    guest_name: booking.guest_name,
    contact_number: booking.contact_number,
    email: booking.email,
    number_of_guests: booking.number_of_guests,
    check_in_date: booking.check_in_date,
    check_out_date: booking.check_out_date,
    check_in_time: booking.check_in_time,
    check_out_time: booking.check_out_time,
    
    facilities: booking.facilities.map(f => ({
      facility_id: f.facility_id,
      rate_id: f.rate_id,
      quantity: f.quantity,
      rate_amount: f.rate_amount
    })),
    
    discount_mode: booking.discount_mode,
    discount_id: booking.discount_id,
    manual_discount_amount: booking.manual_discount_amount,
    
    guest_discounts: booking.guest_discounts || [],
    third_party_services: booking.third_party_services || [],
    
    special_requests: booking.special_requests
  });
  
  // Check edit permissions
  const canFullEdit = booking.booking_status === 'Pending';
  const canContactEdit = booking.booking_status === 'Confirmed';
  const cannotEdit = ['Checked_In', 'Checked_Out', 'Cancelled'].includes(booking.booking_status);
  
  if (cannotEdit) {
    return (
      <div className="alert alert-warning">
        <p>Cannot edit {booking.booking_status} bookings.</p>
        {['Checked_In'].includes(booking.booking_status) && (
          <p>Use extensions to add charges.</p>
        )}
      </div>
    );
  }
  
  const handleSubmit = async (e) => {
    e.preventDefault();
    
    try {
      const token = localStorage.getItem('token');
      const response = await axios.put(
        `/api/booking/${booking.id}`,
        formData,
        { 
          headers: { 
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json'
          } 
        }
      );
      
      if (response.data.changes) {
        alert(
          `Total changed from ₱${response.data.changes.old_total.toFixed(2)} ` +
          `to ₱${response.data.changes.new_total.toFixed(2)}`
        );
      }
      
      onSuccess(response.data.data);
    } catch (error) {
      alert(error.response?.data?.message || 'Update failed');
    }
  };
  
  return (
    <form onSubmit={handleSubmit}>
      {canFullEdit ? (
        // FULL EDIT FORM - Reuse creation form component
        <BookingCreationFields 
          formData={formData}
          onChange={setFormData}
        />
      ) : (
        // CONTACT ONLY FORM
        <>
          <div className="form-group">
            <label>Guest Name *</label>
            <input
              type="text"
              value={formData.guest_name}
              onChange={e => setFormData({...formData, guest_name: e.target.value})}
              required
            />
          </div>
          
          <div className="form-group">
            <label>Contact Number *</label>
            <input
              type="text"
              value={formData.contact_number}
              onChange={e => setFormData({...formData, contact_number: e.target.value})}
              required
            />
          </div>
          
          <div className="form-group">
            <label>Special Requests</label>
            <textarea
              value={formData.special_requests}
              onChange={e => setFormData({...formData, special_requests: e.target.value})}
              rows="3"
            />
          </div>
          
          <div className="alert alert-info">
            <p>To modify facilities or amounts, use the Extensions feature.</p>
          </div>
        </>
      )}
      
      <button type="submit" className="btn btn-primary">
        Update Booking
      </button>
    </form>
  );
}
```

---

### **3. Add Extension Component**

```jsx
import React, { useState } from 'react';
import axios from 'axios';

function AddExtensionModal({ billing, onSuccess, onClose }) {
  const [extensionType, setExtensionType] = useState('facility');
  const [formData, setFormData] = useState({
    description: '',
    amount: 0,
    quantity: 1,
    metadata: {},
    payment_required: false,
    payment_amount: 0,
    payment_method: 'Cash'
  });
  
  // Check if extensions allowed
  const canAddExtension = ['pending', 'confirmed', 'active'].includes(billing.billing_status);
  
  if (!canAddExtension) {
    return (
      <div className="alert alert-danger">
        Extensions cannot be added to {billing.billing_status} billings.
      </div>
    );
  }
  
  const handleSubmit = async (e) => {
    e.preventDefault();
    
    try {
      const token = localStorage.getItem('token');
      const response = await axios.post(
        `/api/billings/${billing.id}/add-extension`,
        {
          extension_type: extensionType,
          ...formData
        },
        { 
          headers: { 
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json'
          } 
        }
      );
      
      alert('Extension added successfully');
      onSuccess(response.data.data);
      onClose();
    } catch (error) {
      alert(error.response?.data?.message || 'Failed to add extension');
    }
  };
  
  return (
    <div className="modal">
      <div className="modal-content">
        <h3>Add Extension</h3>
        
        <form onSubmit={handleSubmit}>
          {/* Extension Type Selector */}
          <div className="extension-type-selector">
            <button
              type="button"
              className={extensionType === 'facility' ? 'active' : ''}
              onClick={() => setExtensionType('facility')}
            >
              🏠 Facility
            </button>
            <button
              type="button"
              className={extensionType === 'guest' ? 'active' : ''}
              onClick={() => setExtensionType('guest')}
            >
              👥 Guest
            </button>
            <button
              type="button"
              className={extensionType === 'damage' ? 'active' : ''}
              onClick={() => setExtensionType('damage')}
            >
              ⚠️ Damage
            </button>
            <button
              type="button"
              className={extensionType === 'service' ? 'active' : ''}
              onClick={() => setExtensionType('service')}
            >
              🛠️ Service
            </button>
          </div>
          
          {/* Dynamic Form Based on Type */}
          {extensionType === 'facility' && (
            <FacilityExtensionForm
              formData={formData}
              onChange={setFormData}
            />
          )}
          
          {extensionType === 'guest' && (
            <GuestExtensionForm
              formData={formData}
              onChange={setFormData}
            />
          )}
          
          {extensionType === 'damage' && (
            <DamageExtensionForm
              formData={formData}
              onChange={setFormData}
            />
          )}
          
          {extensionType === 'service' && (
            <ServiceExtensionForm
              formData={formData}
              onChange={setFormData}
            />
          )}
          
          {/* Payment Section */}
          <div className="payment-section">
            <label>
              <input
                type="checkbox"
                checked={formData.payment_required}
                onChange={e => setFormData({
                  ...formData,
                  payment_required: e.target.checked,
                  payment_amount: e.target.checked ? formData.amount * formData.quantity : 0
                })}
              />
              Guest pays now
            </label>
            
            {formData.payment_required && (
              <>
                <input
                  type="number"
                  value={formData.payment_amount}
                  onChange={e => setFormData({
                    ...formData, 
                    payment_amount: parseFloat(e.target.value)
                  })}
                  placeholder="Payment Amount"
                  max={formData.amount * formData.quantity}
                />
                <select
                  value={formData.payment_method}
                  onChange={e => setFormData({
                    ...formData, 
                    payment_method: e.target.value
                  })}
                >
                  <option value="Cash">Cash</option>
                  <option value="Card">Card</option>
                  <option value="GCash">GCash</option>
                  <option value="Bank Transfer">Bank Transfer</option>
                </select>
              </>
            )}
          </div>
          
          {/* Total Display */}
          <div className="total-display">
            <strong>Total Amount:</strong>
            <span>₱{(formData.amount * formData.quantity).toFixed(2)}</span>
          </div>
          
          <div className="form-actions">
            <button type="button" onClick={onClose}>Cancel</button>
            <button type="submit" className="btn-primary">Add Extension</button>
          </div>
        </form>
      </div>
    </div>
  );
}
```

---

### **4. Facility Extension Form (Reuses Creation Logic)**

```jsx
import React, { useState, useEffect } from 'react';
import axios from 'axios';

function FacilityExtensionForm({ formData, onChange }) {
  const [facilities, setFacilities] = useState([]);
  const [selectedFacility, setSelectedFacility] = useState(null);
  const [rates, setRates] = useState([]);
  
  // Load facilities (same as creation)
  useEffect(() => {
    const token = localStorage.getItem('token');
    axios.get('/api/facilities', {
      headers: { Authorization: `Bearer ${token}` }
    }).then(res => {
      setFacilities(res.data.data);
    });
  }, []);
  
  const handleFacilitySelect = async (facilityId) => {
    const facility = facilities.find(f => f.id === facilityId);
    setSelectedFacility(facility);
    
    // Load rates for this facility (same as creation)
    const token = localStorage.getItem('token');
    const response = await axios.get(
      `/api/facilities/${facilityId}/rates`,
      { headers: { Authorization: `Bearer ${token}` } }
    );
    setRates(response.data.data);
  };
  
  const handleRateSelect = (rateId) => {
    const rate = rates.find(r => r.id === rateId);
    
    onChange({
      ...formData,
      amount: rate.base_price,
      description: `Additional ${selectedFacility.name} - ${rate.rate_name}`,
      metadata: {
        facility_id: selectedFacility.id,
        facility_name: selectedFacility.name,
        rate_id: rate.id,
        rate_name: rate.rate_name
      }
    });
  };
  
  return (
    <>
      <div className="form-group">
        <label>Select Facility *</label>
        <select onChange={e => handleFacilitySelect(parseInt(e.target.value))}>
          <option value="">Choose facility...</option>
          {facilities.map(f => (
            <option key={f.id} value={f.id}>
              {f.name} ({f.facility_type?.name})
            </option>
          ))}
        </select>
      </div>
      
      {rates.length > 0 && (
        <div className="form-group">
          <label>Select Rate *</label>
          <select onChange={e => handleRateSelect(parseInt(e.target.value))}>
            <option value="">Choose rate...</option>
            {rates.map(r => (
              <option key={r.id} value={r.id}>
                {r.rate_name} - ₱{r.base_price}
              </option>
            ))}
          </select>
        </div>
      )}
      
      <div className="form-group">
        <label>Quantity *</label>
        <input
          type="number"
          value={formData.quantity}
          onChange={e => onChange({
            ...formData, 
            quantity: parseInt(e.target.value)
          })}
          min="1"
          required
        />
      </div>
      
      <div className="form-group">
        <label>Description *</label>
        <textarea
          value={formData.description}
          onChange={e => onChange({
            ...formData, 
            description: e.target.value
          })}
          rows="2"
          required
        />
      </div>
    </>
  );
}
```

---

## ✅ Testing Checklist

### **Edit Booking Tests**

#### Pending Status
- [ ] Edit all fields successfully
- [ ] Booking type change (Swimming ↔ Package)
- [ ] Add/remove facilities
- [ ] Change dates
- [ ] Change guest count
- [ ] Change discount mode
- [ ] Verify billing balance updates correctly
- [ ] See old vs new total in response
- [ ] Verify facilities are replaced (not added)

#### Confirmed Status
- [ ] Edit contact details only
- [ ] Cannot change facilities (hint shown)
- [ ] Cannot change dates
- [ ] Cannot change amounts
- [ ] See hint about using extensions

#### Checked-In Status
- [ ] Get 422 error when trying to edit
- [ ] Error message mentions extensions
- [ ] Extension button shown instead

#### Checked-Out Status
- [ ] Get 422 error
- [ ] No edit button shown
- [ ] No extension button shown

---

### **Walk-in Edit Tests**

- [ ] Active walk-in → 403 error
- [ ] Error message mentions extensions
- [ ] Extension button shown
- [ ] Checked-out walk-in → 403 error
- [ ] No edit functionality available

---

### **Extension Tests**

#### All Types
- [ ] Add facility extension
- [ ] Add guest extension
- [ ] Add damage extension
- [ ] Add service extension
- [ ] Extension with immediate payment
- [ ] Extension without payment
- [ ] Verify billing balance increases
- [ ] Verify extension appears in billing.extensions array

#### Facility Extension
- [ ] Same facility picker as creation
- [ ] Rate selection works
- [ ] Amount auto-fills from rate
- [ ] Metadata saved correctly

#### Guest Extension
- [ ] Guest count input
- [ ] Amount per guest
- [ ] Optional guest names
- [ ] Metadata saved

#### Damage Extension
- [ ] Free-form description
- [ ] Cost input
- [ ] Optional damage details
- [ ] Metadata saved

#### Service Extension
- [ ] Service name input
- [ ] Hourly rate option
- [ ] Quantity (hours/units)
- [ ] Provider details optional

#### Status Validation
- [ ] Can add to pending billing
- [ ] Can add to confirmed billing
- [ ] Can add to active billing
- [ ] Cannot add to completed billing (422 error)
- [ ] Cannot add to voided billing (422 error)

#### Payment Tests
- [ ] Payment recorded if payment_required = true
- [ ] Balance decreases by payment_amount
- [ ] Payment appears in billing.payments
- [ ] Can partial pay extension
- [ ] Payment method saved correctly

---

## 📝 Quick Reference

### **Edit Rules Summary**

```
┌──────────────────────────────────────────────────────┐
│ TYPE          │ STATUS        │ EDIT ACTION          │
├───────────────┼───────────────┼──────────────────────┤
│ Booking       │ Pending       │ ✅ Full Edit         │
│ Booking       │ Confirmed     │ ⚠️  Contact Only    │
│ Booking       │ Checked_In    │ ❌ No Edit (Extend)  │
│ Booking       │ Checked_Out   │ ❌ No Edit           │
│ Walk-in       │ Any           │ ❌ No Edit (Extend)  │
└──────────────────────────────────────────────────────┘
```

### **Extension Rules Summary**

```
┌──────────────────────────────────────────────────────┐
│ BILLING STATUS       │ EXTENSIONS ALLOWED            │
├──────────────────────┼───────────────────────────────┤
│ pending              │ ✅ YES                        │
│ confirmed            │ ✅ YES                        │
│ active               │ ✅ YES                        │
│ completed            │ ❌ NO                         │
│ voided               │ ❌ NO                         │
└──────────────────────────────────────────────────────┘

Extension Types: facility | guest | damage | service
```

### **Key Points**

✅ **Edit = Same as Creation**
- Same form fields and structure
- Same validation rules
- Same facility/rate/discount pickers
- Just PUT instead of POST
- Replaces original data

✅ **Extension = Creation Logic**
- Same facility selection process
- Same rate selection process
- Same discount application (if applicable)
- Just adds to existing billing
- Preserves original data

✅ **Status Matters**
- Pending bookings → Full edit
- Confirmed bookings → Contact only
- Active/checked-in → Extensions only
- Completed/checked-out → No changes

✅ **Walk-ins Different**
- Never editable (payment immediate)
- Always use extensions
- Same extension rules as bookings

---

## 🎯 Summary

### **For Developers**

1. **Edit Booking**
   - Reuse creation form component
   - Check booking_status first
   - Show appropriate fields based on status
   - Handle changes response with old/new totals

2. **Extensions**
   - Reuse creation facility/rate/discount pickers
   - Add type selector (4 types)
   - Support immediate payment option
   - Check billing_status before showing

3. **Walk-ins**
   - Never show edit button
   - Always show extensions button
   - Use same extension logic as bookings

### **Backend Behavior**

- **Edit** completely replaces old data
- **Extension** adds to existing data
- Billing balance recalculated automatically
- All changes tracked with user ID and timestamp
- Extensions can be paid immediately or later

### **Frontend Strategy**

✅ Maximum code reuse:
- Creation form → Edit form
- Facility picker → Extension facility picker
- Rate selector → Extension rate selector
- Discount logic → Extension discount logic

✅ Just change:
- Endpoint (PUT for edit, POST for extension)
- Button labels ("Update" vs "Add")
- Status checks (what's allowed when)

This approach minimizes code duplication and maintains consistency! 🚀
