# Edit, Refund, and Extensions Features - Frontend Implementation Guide

## Overview
Three new features have been implemented in the backend API for the resort management system:
1. **Edit Booking** - Modify booking details (restricted to Pending status only)
2. **Cancel/Refund** - Cancel bookings with intelligent refund calculation based on downpayment policy
3. **Extensions** - Add mid-stay charges (facilities, guests, damages, services)

All backend endpoints are fully tested and working. This guide provides everything needed for frontend implementation.

---

## 🔌 API Endpoints

### 1. Edit Booking
**Endpoint:** `PUT /api/booking/{id}`

**Authorization:** Bearer Token required

**Request Body:**
```json
{
  "guest_name": "Updated Guest Name",
  "contact_number": "09171234567",
  "email": "guest@example.com",
  "number_of_guests": 6,
  "check_in_date": "2025-11-20",
  "check_out_date": "2025-11-22",
  "special_requests": "Optional notes"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Booking updated successfully",
  "data": {
    "booking": {
      "id": 123,
      "booking_number": "BK-001",
      "booking_status": "Pending",
      "guest_name": "Updated Guest Name",
      "number_of_guests": 6
    },
    "billing": {
      "id": 456,
      "total_amount": "7000.00",
      "balance": "7000.00",
      "subtotal": "6000.00",
      "discount_amount": "0.00"
    }
  }
}
```

**Error Response (422):**
```json
{
  "success": false,
  "message": "Cannot edit booking. Only Pending bookings can be edited.",
  "error": "Invalid booking status"
}
```

**Business Rules:**
- ✅ Only `Pending` bookings can be edited
- ❌ `Checked_In`, `Checked_Out`, `Cancelled` bookings cannot be edited
- Billing totals are automatically recalculated based on new guest count/dates
- Balance is updated to reflect the new total

---

### 2. Cancel/Refund Booking
**Endpoint:** `POST /api/booking/{id}/cancel-refund`

**Authorization:** Bearer Token required

**Request Body:**
```json
{
  "refund_amount": 7000.00,
  "refund_reason": "Customer requested cancellation",
  "override_downpayment_policy": false
}
```

**Parameters:**
- `refund_amount` (required, numeric): Amount to refund
- `refund_reason` (optional, string): Reason for cancellation
- `override_downpayment_policy` (optional, boolean): `true` = refund full amount including downpayment (manager override), `false` = apply standard policy (downpayment non-refundable)

**Success Response (200):**
```json
{
  "success": true,
  "message": "Booking cancelled and refund processed successfully",
  "data": {
    "booking": {
      "id": 123,
      "booking_status": "Cancelled"
    },
    "billing": {
      "id": 456,
      "billing_status": "voided",
      "payment_status": "refunded",
      "refund_amount": "7000.00",
      "refund_reason": "Customer requested cancellation",
      "refunded_by": 18,
      "refunded_at": "2025-11-17 22:16:30"
    },
    "policy_applied": {
      "total_paid": "10000.00",
      "downpayment_paid": "3000.00",
      "max_refundable": "7000.00",
      "refund_issued": "7000.00",
      "downpayment_retained": "3000.00"
    }
  }
}
```

**Business Rules:**
- Default: Downpayment is **non-refundable**
- Only the balance portion (total_paid - downpayment) can be refunded
- Manager can override policy by setting `override_downpayment_policy: true`
- Booking status changes to `Cancelled`
- Billing status changes to `voided` with payment_status `refunded`
- All refund details tracked: amount, reason, user, timestamp

---

### 3. Add Extension (Mid-Stay Charges)
**Endpoint:** `POST /api/billings/{id}/add-extension`

**Authorization:** Bearer Token required

**Request Body:**
```json
{
  "extension_type": "facility",
  "description": "Additional cottage for Day 2",
  "amount": 1500.00,
  "quantity": 1,
  "metadata": {
    "facility_id": 5,
    "facility_name": "Cottage B"
  },
  "payment_amount": 1500.00,
  "payment_method": "Cash"
}
```

**Parameters:**
- `extension_type` (required, enum): `facility`, `guest`, `damage`, `service`
- `description` (required, string): Description of the charge
- `amount` (required, numeric): Price per unit
- `quantity` (optional, numeric, default: 1): Quantity/hours/count
- `metadata` (optional, json): Additional details (facility_id, guest_ids, etc.)
- `payment_amount` (optional, numeric): If paying immediately
- `payment_method` (optional, string): Cash/Card/Online if payment_amount provided

**Success Response (200):**
```json
{
  "success": true,
  "message": "Extension added successfully",
  "data": {
    "extension": {
      "id": 789,
      "billing_id": 456,
      "extension_type": "facility",
      "description": "Additional cottage for Day 2",
      "amount": "1500.00",
      "quantity": 1,
      "total_amount": "1500.00",
      "added_by": 18,
      "created_at": "2025-11-17 22:17:10"
    },
    "billing": {
      "id": 456,
      "total_amount": "7500.00",
      "balance": "3500.00",
      "amount_paid": "4000.00"
    },
    "payment": {
      "id": 321,
      "amount": "1500.00",
      "payment_method": "Cash"
    }
  }
}
```

**Extension Types:**
1. **facility** - Additional facilities (cottages, rooms, equipment)
2. **guest** - Extra guests beyond booking capacity
3. **damage** - Damage charges (broken items, lost equipment)
4. **service** - Third-party services (videography, catering, decorations)

**Business Rules:**
- Extensions can only be added to `active` billings
- Cannot add to `completed`, `voided`, or `refunded` billings
- Total amount calculated as: `amount × quantity`
- Billing total and balance automatically updated
- If `payment_amount` provided, payment record created immediately

---

## 📋 Database Schema Reference

### Valid Enum Values
```javascript
// Booking Status
const bookingStatuses = [
  'Pending',
  'Confirmed', 
  'Checked_In',
  'Checked_Out',
  'Cancelled',
  'No_Show'
];

// Billing Status
const billingStatuses = [
  'pending',    // Not yet finalized
  'active',     // Active billing
  'completed',  // Fully paid and closed
  'voided'      // Cancelled/Refunded
];

// Payment Status
const paymentStatuses = [
  'unpaid',     // No payment made
  'partial',    // Partially paid
  'paid',       // Fully paid
  'refunded',   // Refunded
  'cancelled'   // Cancelled
];

// Extension Types
const extensionTypes = [
  'facility',   // Additional facilities
  'guest',      // Extra guests
  'damage',     // Damage charges
  'service'     // Third-party services
];
```

### New Database Fields

**billings table:**
```sql
refund_amount DECIMAL(10,2) NULL
refund_reason VARCHAR(255) NULL
refunded_by BIGINT UNSIGNED NULL (FK to users.id)
refunded_at TIMESTAMP NULL
```

**billing_extensions table (new):**
```sql
id BIGINT UNSIGNED PRIMARY KEY
billing_id BIGINT UNSIGNED (FK to billings.id)
extension_type ENUM('facility','guest','damage','service')
description VARCHAR(255)
amount DECIMAL(10,2)
quantity INT DEFAULT 1
total_amount DECIMAL(10,2)
metadata JSON NULL
added_by BIGINT UNSIGNED (FK to users.id)
created_at TIMESTAMP
updated_at TIMESTAMP
```

---

## 🎨 Frontend Implementation Guide

### 1. Edit Booking Feature

**UI Components Needed:**
- Edit button (shown only for Pending bookings)
- Edit booking form/modal with ALL booking creation fields
- Confirmation dialog for changes

**Form Fields Required:**
```javascript
// Basic Information
- guest_name (text input)
- contact_number (text input)
- email (text input, optional)
- number_of_guests (number input)
- check_in_date (date picker)
- check_out_date (date picker)
- special_requests (textarea)

// Booking Type
- booking_type (radio/select: "Package" or "Swimming")

// For Swimming bookings:
- entrance_rate_id (select dropdown - fetch from GET /api/rates?category=Entrance)

// Facilities (REQUIRED - fetch from GET /api/facilities)
- facilities[] (array of selected facilities)
  - facility_id (select dropdown)
  - rate_id (select dropdown - rates for selected facility)
  - quantity (number input)
  - rate_amount (auto-calculated from selected rate)

// Discounts
- discount_mode (radio: "None", "Seasonal", "Direct")
  
  // If "Seasonal":
  - discount_id (select dropdown - fetch from GET /api/discounts?category=Seasonal)
  
  // If "Direct":
  - guest_discounts[] (array)
    - guest_type (e.g., "Senior", "PWD", "Student")
    - count (number of guests with this discount)
    - discount_id (select dropdown - fetch from GET /api/discounts?category=Direct)
  
  // Manual discount:
  - manual_discount_amount (number input, optional)

// Third-party Services (optional)
- third_party_services[] (array)
  - service_name (text input)
  - amount (number input)
```

**Implementation Steps:**
```javascript
// 1. Check if booking can be edited
const canEdit = booking.booking_status === 'Pending';

// 2. Load current booking data into form
async function loadEditForm(bookingId) {
  // Fetch full booking details
  const response = await axios.get(`/api/booking/${bookingId}`);
  const booking = response.data.data;
  
  // Pre-populate form with current values
  form.guest_name = booking.guest_name;
  form.contact_number = booking.contact_number;
  form.email = booking.email;
  form.number_of_guests = booking.number_of_guests;
  form.check_in_date = booking.check_in_date;
  form.check_out_date = booking.check_out_date;
  form.booking_type = booking.booking_type;
  form.entrance_rate_id = booking.entrance_rate_id;
  form.special_requests = booking.special_requests;
  
  // Pre-populate facilities
  form.facilities = booking.facilities.map(f => ({
    facility_id: f.facility_id,
    rate_id: f.rate_id,
    quantity: f.quantity,
    rate_amount: f.rate?.base_price || 0
  }));
  
  // Pre-populate discounts
  form.discount_mode = booking.discount_mode || 'None';
  form.discount_id = booking.discount_id;
  form.manual_discount_amount = booking.manual_discount_amount;
  
  if (booking.guest_discounts) {
    form.guest_discounts = booking.guest_discounts.map(gd => ({
      guest_type: gd.guest_type,
      count: gd.guest_count,
      discount_id: gd.discount?.id
    }));
  }
  
  // Pre-populate third-party services
  if (booking.third_party_services) {
    form.third_party_services = booking.third_party_services.map(s => ({
      service_name: s.service_name,
      amount: s.amount
    }));
  }
}

// 3. Fetch required data for dropdowns
async function loadFormData() {
  // Fetch facilities
  const facilitiesResponse = await axios.get('/api/facilities');
  availableFacilities = facilitiesResponse.data.data;
  
  // Fetch entrance rates (for Swimming bookings)
  const entranceRatesResponse = await axios.get('/api/rates?category=Entrance');
  entranceRates = entranceRatesResponse.data.data;
  
  // Fetch seasonal discounts
  const seasonalDiscountsResponse = await axios.get('/api/discounts?category=Seasonal&is_active=1');
  seasonalDiscounts = seasonalDiscountsResponse.data.data;
  
  // Fetch direct discounts
  const directDiscountsResponse = await axios.get('/api/discounts?category=Direct&is_active=1');
  directDiscounts = directDiscountsResponse.data.data;
}

// 4. Handle facility selection (fetch rates for selected facility)
async function onFacilitySelected(facilityIndex) {
  const facility = form.facilities[facilityIndex];
  
  // Fetch rates for this facility
  const response = await axios.get(`/api/facilities/${facility.facility_id}/rates`);
  facility.availableRates = response.data.data;
}

// 5. Handle rate selection (auto-fill rate_amount)
function onRateSelected(facilityIndex) {
  const facility = form.facilities[facilityIndex];
  const selectedRate = facility.availableRates.find(r => r.id === facility.rate_id);
  
  if (selectedRate) {
    facility.rate_amount = selectedRate.base_price;
  }
}

// 6. Submit edited booking
async function editBooking(bookingId, formData) {
  try {
    const response = await axios.put(`/api/booking/${bookingId}`, {
      // Basic info
      guest_name: formData.guest_name,
      contact_number: formData.contact_number,
      email: formData.email,
      number_of_guests: parseInt(formData.number_of_guests),
      check_in_date: formData.check_in_date,
      check_out_date: formData.check_out_date,
      special_requests: formData.special_requests,
      
      // Booking type
      booking_type: formData.booking_type,
      entrance_rate_id: formData.entrance_rate_id,
      
      // Facilities (REQUIRED - must include all fields)
      facilities: formData.facilities.map(f => ({
        facility_id: f.facility_id,
        rate_id: f.rate_id,
        quantity: f.quantity,
        rate_amount: f.rate_amount
      })),
      
      // Discounts
      discount_mode: formData.discount_mode,
      discount_id: formData.discount_id,
      manual_discount_amount: formData.manual_discount_amount || 0,
      guest_discounts: formData.guest_discounts || [],
      
      // Third-party services
      third_party_services: formData.third_party_services || []
    }, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
      }
    });

    if (response.data.success) {
      // Update UI with new booking and billing data
      updateBookingDisplay(response.data.data.booking);
      updateBillingDisplay(response.data.data.billing);
      showSuccessMessage('Booking updated successfully');
    }
  } catch (error) {
    if (error.response?.status === 422) {
      showErrorMessage(error.response.data.message);
    } else {
      showErrorMessage('Failed to update booking');
    }
  }
}
```

**UI Example - Edit Form Structure:**
```html
<form @submit.prevent="submitEdit">
  <!-- Guest Information Section -->
  <section class="guest-info">
    <h3>Guest Information</h3>
    <input v-model="form.guest_name" placeholder="Guest Name" required>
    <input v-model="form.contact_number" placeholder="Contact Number" required>
    <input v-model="form.email" placeholder="Email (optional)">
    <input v-model.number="form.number_of_guests" type="number" min="1" required>
  </section>

  <!-- Booking Dates Section -->
  <section class="booking-dates">
    <h3>Booking Dates</h3>
    <input v-model="form.check_in_date" type="date" required>
    <input v-model="form.check_out_date" type="date" required>
  </section>

  <!-- Booking Type Section -->
  <section class="booking-type">
    <h3>Booking Type</h3>
    <select v-model="form.booking_type" required>
      <option value="Package">Package</option>
      <option value="Swimming">Swimming</option>
    </select>
    
    <!-- Entrance Rate (only for Swimming) -->
    <select v-if="form.booking_type === 'Swimming'" v-model="form.entrance_rate_id" required>
      <option v-for="rate in entranceRates" :key="rate.id" :value="rate.id">
        {{ rate.rate_name }} - ₱{{ rate.base_price }}
      </option>
    </select>
  </section>

  <!-- Facilities Section (REQUIRED) -->
  <section class="facilities">
    <h3>Facilities</h3>
    <div v-for="(facility, index) in form.facilities" :key="index" class="facility-item">
      <!-- Facility Selection -->
      <select v-model="facility.facility_id" @change="onFacilitySelected(index)" required>
        <option value="">Select Facility</option>
        <option v-for="f in availableFacilities" :key="f.id" :value="f.id">
          {{ f.name }}
        </option>
      </select>
      
      <!-- Rate Selection (loaded after facility selected) -->
      <select v-model="facility.rate_id" @change="onRateSelected(index)" required>
        <option value="">Select Rate</option>
        <option v-for="rate in facility.availableRates" :key="rate.id" :value="rate.id">
          {{ rate.rate_name }} - ₱{{ rate.base_price }}
        </option>
      </select>
      
      <!-- Quantity -->
      <input v-model.number="facility.quantity" type="number" min="1" placeholder="Quantity" required>
      
      <!-- Amount (auto-filled) -->
      <span>₱{{ (facility.rate_amount * facility.quantity).toFixed(2) }}</span>
      
      <!-- Remove button -->
      <button type="button" @click="removeFacility(index)">Remove</button>
    </div>
    
    <button type="button" @click="addFacility">+ Add Facility</button>
  </section>

  <!-- Discounts Section -->
  <section class="discounts">
    <h3>Discounts</h3>
    <select v-model="form.discount_mode">
      <option value="None">No Discount</option>
      <option value="Seasonal">Seasonal Discount</option>
      <option value="Direct">Direct Discount (PWD/Senior)</option>
    </select>
    
    <!-- Seasonal Discount -->
    <select v-if="form.discount_mode === 'Seasonal'" v-model="form.discount_id">
      <option v-for="discount in seasonalDiscounts" :key="discount.id" :value="discount.id">
        {{ discount.discount_name }} - {{ discount.formatted_value }}
      </option>
    </select>
    
    <!-- Direct Discount -->
    <div v-if="form.discount_mode === 'Direct'">
      <div v-for="(gd, index) in form.guest_discounts" :key="index">
        <select v-model="gd.discount_id">
          <option v-for="discount in directDiscounts" :key="discount.id" :value="discount.id">
            {{ discount.discount_name }}
          </option>
        </select>
        <input v-model.number="gd.count" type="number" min="1" placeholder="Number of guests">
        <button type="button" @click="removeGuestDiscount(index)">Remove</button>
      </div>
      <button type="button" @click="addGuestDiscount">+ Add Guest Discount</button>
    </div>
    
    <!-- Manual Discount -->
    <div>
      <label>Manual Discount:</label>
      <input v-model.number="form.manual_discount_amount" type="number" min="0" placeholder="₱0.00">
    </div>
  </section>

  <!-- Third-Party Services Section (Optional) -->
  <section class="services">
    <h3>Third-Party Services (Optional)</h3>
    <div v-for="(service, index) in form.third_party_services" :key="index">
      <input v-model="service.service_name" placeholder="Service Name">
      <input v-model.number="service.amount" type="number" min="0" placeholder="Amount">
      <button type="button" @click="removeService(index)">Remove</button>
    </div>
    <button type="button" @click="addService">+ Add Service</button>
  </section>

  <!-- Special Requests -->
  <section class="special-requests">
    <h3>Special Requests</h3>
    <textarea v-model="form.special_requests" rows="3"></textarea>
  </section>

  <!-- Submit Buttons -->
  <div class="form-actions">
    <button type="button" @click="closeModal">Cancel</button>
    <button type="submit" class="primary">Update Booking</button>
  </div>
</form>
```

**UI Behavior:**
- Show "Edit" button only when `booking_status === 'Pending'`
- Pre-fill form with current booking data
- Display warning if total changes: "Total amount will change from ₱X to ₱Y"
- After successful edit, refresh both booking and billing details
- Show error message if booking cannot be edited

---

### 2. Add Extension Feature

**UI Components Needed:**
- "Add Charge" button (shown for pending/confirmed/active billings)
- Add extension form/modal with dynamic fields based on extension type
- Optional facility/rate selection for better UX
- Extension history list

**Form Fields Required:**
```javascript
// Step 1: Extension Type (determines what fields to show)
- extension_type (required - radio/select)
  Options: "facility", "guest", "damage", "service"

// Step 2: Details (changes based on extension_type)
// For ALL types:
- description (required - text input or auto-generated)
- amount (required - number input or auto-filled)
- quantity (required - number input, default 1)
- metadata (optional - json object for extra details)

// Step 3: Payment (optional)
- payment_amount (optional - number input)
- payment_method (optional - select: Cash, Card, GCash, etc.)
```

**Implementation with Facility Selection (Enhanced UX):**
```javascript
// 1. Fetch available facilities with rates for extension form
async function loadExtensionFormData() {
  // Fetch all active facilities
  const facilitiesResponse = await axios.get('/api/facilities?status=active');
  availableFacilities = facilitiesResponse.data.data;
  
  // For each facility, fetch its rates
  for (let facility of availableFacilities) {
    const ratesResponse = await axios.get(`/api/facilities/${facility.id}/rates`);
    facility.rates = ratesResponse.data.data;
  }
}

// 2. Handle extension type selection
function onExtensionTypeChange() {
  // Reset form fields
  form.selectedFacility = null;
  form.selectedRate = null;
  form.description = '';
  form.amount = 0;
  form.quantity = 1;
  
  // Show different fields based on type
  if (form.extension_type === 'facility') {
    showFacilitySelector = true;
  } else {
    showFacilitySelector = false;
  }
}

// 3. Handle facility selection (for facility extensions)
function onFacilitySelected() {
  const facility = availableFacilities.find(f => f.id === form.selectedFacility);
  
  if (facility) {
    // Auto-fill description with facility name
    form.description = `Additional ${facility.name}`;
    
    // Show rate options for this facility
    form.availableRates = facility.rates;
  }
}

// 4. Handle rate selection
function onRateSelected() {
  const rate = form.availableRates.find(r => r.id === form.selectedRate);
  
  if (rate) {
    // Auto-fill amount from rate
    form.amount = rate.base_price;
    
    // Update description
    form.description = `Additional ${selectedFacilityName} - ${rate.rate_name}`;
    
    // Store in metadata
    form.metadata = {
      facility_id: form.selectedFacility,
      facility_name: selectedFacilityName,
      rate_id: form.selectedRate,
      rate_name: rate.rate_name
    };
  }
}

// 5. Calculate total
function calculateTotal() {
  return form.amount * form.quantity;
}

// 6. Submit extension
async function addExtension(billingId, extensionData) {
  try {
    const response = await axios.post(`/api/billings/${billingId}/add-extension`, {
      extension_type: extensionData.extension_type,
      description: extensionData.description,
      amount: parseFloat(extensionData.amount),
      quantity: parseInt(extensionData.quantity) || 1,
      metadata: extensionData.metadata || {},
      payment_amount: extensionData.payment_amount || null,
      payment_method: extensionData.payment_method || null
    }, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
      }
    });

    if (response.data.success) {
      const { extension, billing, payment } = response.data.data;
      
      // Update billing totals in UI
      updateBillingTotals(billing);
      
      // Add extension to history list
      addExtensionToList(extension);
      
      // Show payment confirmation if paid
      if (payment) {
        showPaymentConfirmation(payment);
      }
      
      showSuccessMessage('Charge added successfully');
      closeModal();
    }
  } catch (error) {
    if (error.response?.status === 422) {
      showErrorMessage(error.response.data.message);
    } else {
      showErrorMessage('Failed to add charge');
    }
  }
}
```

**UI Example - Add Extension Form:**
```html
<form @submit.prevent="submitExtension">
  <!-- Step 1: Select Extension Type -->
  <section class="extension-type">
    <h3>Type of Charge</h3>
    <div class="type-selector">
      <label>
        <input type="radio" v-model="form.extension_type" value="facility" @change="onExtensionTypeChange">
        <span class="type-card">
          <i class="icon-facility"></i>
          <strong>Facility</strong>
          <small>Additional cottages, equipment</small>
        </span>
      </label>
      
      <label>
        <input type="radio" v-model="form.extension_type" value="guest" @change="onExtensionTypeChange">
        <span class="type-card">
          <i class="icon-guest"></i>
          <strong>Guest</strong>
          <small>Extra overnight guests</small>
        </span>
      </label>
      
      <label>
        <input type="radio" v-model="form.extension_type" value="damage" @change="onExtensionTypeChange">
        <span class="type-card">
          <i class="icon-damage"></i>
          <strong>Damage</strong>
          <small>Broken items, lost property</small>
        </span>
      </label>
      
      <label>
        <input type="radio" v-model="form.extension_type" value="service" @change="onExtensionTypeChange">
        <span class="type-card">
          <i class="icon-service"></i>
          <strong>Service</strong>
          <small>Videography, catering, etc.</small>
        </span>
      </label>
    </div>
  </section>

  <!-- Step 2: Extension Details (changes based on type) -->
  
  <!-- For FACILITY Extension -->
  <section v-if="form.extension_type === 'facility'" class="extension-details">
    <h3>Facility Details</h3>
    
    <!-- Option 1: Select from existing facilities (better UX) -->
    <div class="facility-selection">
      <label>Select Facility:</label>
      <select v-model="form.selectedFacility" @change="onFacilitySelected">
        <option value="">Choose a facility</option>
        <option v-for="facility in availableFacilities" :key="facility.id" :value="facility.id">
          {{ facility.name }} ({{ facility.facility_type.name }})
        </option>
      </select>
      
      <!-- Rate selection (shown after facility selected) -->
      <div v-if="form.availableRates && form.availableRates.length">
        <label>Select Rate:</label>
        <select v-model="form.selectedRate" @change="onRateSelected">
          <option value="">Choose a rate</option>
          <option v-for="rate in form.availableRates" :key="rate.id" :value="rate.id">
            {{ rate.rate_name }} - ₱{{ rate.base_price.toFixed(2) }}
          </option>
        </select>
      </div>
    </div>
    
    <!-- OR: Manual entry -->
    <div class="manual-entry">
      <p><em>Or enter manually:</em></p>
      <input v-model="form.description" placeholder="Description (e.g., Additional Cottage B)" required>
      <input v-model.number="form.amount" type="number" step="0.01" min="0" placeholder="Amount per unit" required>
    </div>
    
    <div>
      <label>Quantity:</label>
      <input v-model.number="form.quantity" type="number" min="1" placeholder="1" required>
    </div>
  </section>
  
  <!-- For GUEST Extension -->
  <section v-if="form.extension_type === 'guest'" class="extension-details">
    <h3>Guest Details</h3>
    <input v-model="form.description" placeholder="Description (e.g., 2 additional overnight guests)" required>
    <input v-model.number="form.amount" type="number" step="0.01" min="0" placeholder="Amount per guest (e.g., 350)" required>
    <input v-model.number="form.quantity" type="number" min="1" placeholder="Number of guests" required>
    
    <!-- Optional: Guest names -->
    <div class="guest-names">
      <label>Guest Names (optional):</label>
      <input v-model="guestName1" placeholder="Guest 1">
      <input v-model="guestName2" placeholder="Guest 2">
    </div>
  </section>
  
  <!-- For DAMAGE Extension -->
  <section v-if="form.extension_type === 'damage'" class="extension-details">
    <h3>Damage Details</h3>
    <input v-model="form.description" placeholder="Description (e.g., Broken cottage window)" required>
    <input v-model.number="form.amount" type="number" step="0.01" min="0" placeholder="Damage cost" required>
    <input v-model.number="form.quantity" type="number" min="1" value="1" required>
    
    <!-- Optional: Damage details -->
    <div class="damage-info">
      <label>Item Damaged:</label>
      <input v-model="damageItem" placeholder="e.g., Window">
      
      <label>Location:</label>
      <input v-model="damageLocation" placeholder="e.g., Cottage A">
      
      <label>Incident Date:</label>
      <input v-model="incidentDate" type="date">
    </div>
  </section>
  
  <!-- For SERVICE Extension -->
  <section v-if="form.extension_type === 'service'" class="extension-details">
    <h3>Service Details</h3>
    <input v-model="form.description" placeholder="Description (e.g., Videography service)" required>
    <input v-model.number="form.amount" type="number" step="0.01" min="0" placeholder="Amount per hour/unit" required>
    <input v-model.number="form.quantity" type="number" min="1" placeholder="Hours/Quantity" required>
    
    <!-- Optional: Service provider -->
    <div class="service-info">
      <label>Service Provider (optional):</label>
      <input v-model="serviceProvider" placeholder="e.g., XYZ Productions">
      
      <label>Time/Duration:</label>
      <input v-model="serviceTime" placeholder="e.g., 14:00-17:00 (3 hours)">
    </div>
  </section>

  <!-- Total Amount Display -->
  <div class="total-calculation">
    <strong>Total Amount:</strong>
    <span class="total">₱{{ (form.amount * form.quantity).toFixed(2) }}</span>
  </div>

  <!-- Step 3: Payment (Optional) -->
  <section class="payment-section">
    <h3>Payment (Optional)</h3>
    <label>
      <input type="checkbox" v-model="payNow">
      Guest pays for this charge now
    </label>
    
    <div v-if="payNow" class="payment-details">
      <div>
        <label>Payment Amount:</label>
        <input v-model.number="form.payment_amount" type="number" step="0.01" min="0" 
               :max="form.amount * form.quantity" placeholder="₱0.00" required>
      </div>
      
      <div>
        <label>Payment Method:</label>
        <select v-model="form.payment_method" required>
          <option value="Cash">Cash</option>
          <option value="Card">Card</option>
          <option value="GCash">GCash</option>
          <option value="Bank Transfer">Bank Transfer</option>
        </select>
      </div>
    </div>
  </section>

  <!-- Form Actions -->
  <div class="form-actions">
    <button type="button" @click="closeModal">Cancel</button>
    <button type="submit" class="primary">Add Charge</button>
  </div>
</form>
```

**Extension History Display:**
```html
<div class="extensions-list">
  <h4>Additional Charges</h4>
  
  <table>
    <thead>
      <tr>
        <th>Type</th>
        <th>Description</th>
        <th>Amount</th>
        <th>Qty</th>
        <th>Total</th>
        <th>Date Added</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="ext in billing.extensions" :key="ext.id">
        <td>
          <span class="badge" :class="`badge-${ext.extension_type}`">
            {{ ext.extension_type }}
          </span>
        </td>
        <td>{{ ext.description }}</td>
        <td>₱{{ parseFloat(ext.amount).toFixed(2) }}</td>
        <td>{{ ext.quantity }}</td>
        <td><strong>₱{{ parseFloat(ext.total_amount).toFixed(2) }}</strong></td>
        <td>{{ formatDate(ext.created_at) }}</td>
      </tr>
    </tbody>
  </table>
  
  <div class="extensions-summary">
    <strong>Total Extensions:</strong>
    <span>₱{{ totalExtensions.toFixed(2) }}</span>
  </div>
</div>
```

---

## 🔒 Authorization & Permissions

**Role-Based Access:**
```javascript
// Edit Booking
const canEditBooking = (user, booking) => {
  return booking.booking_status === 'Pending' && 
         (user.role === 'admin' || user.role === 'staff');
};

// Add Extensions
const canAddExtensions = (user, billing) => {
  return ['pending', 'confirmed', 'active'].includes(billing.billing_status) &&
         (user.role === 'admin' || user.role === 'staff');
};
```

---

## ✅ Testing Checklist

### Edit Booking:
- [ ] Edit button appears only for Pending bookings
- [ ] Edit button hidden for Checked_In/Checked_Out/Cancelled
- [ ] Form pre-fills with current booking data
- [ ] Billing total updates when guest count changes
- [ ] Success message shown after edit
- [ ] Error message shown if edit rejected

### Cancel/Refund:
- [ ] Refund calculation displays correctly
- [ ] Downpayment shown as non-refundable by default
- [ ] Manager override checkbox works (if authorized)
- [ ] Refund reason can be entered
- [ ] Refund summary displays after success
- [ ] Booking status updates to Cancelled

### Extensions:
- [ ] Add charge button shown for active billings
- [ ] All 4 extension types selectable
- [ ] Amount × Quantity calculates correctly
- [ ] Billing total increases after adding extension
- [ ] Extension appears in history list
- [ ] Optional payment can be recorded immediately

---

## 📊 Sample API Test Data

```javascript
// Test Edit Booking
const editTest = {
  bookingId: 123,
  data: {
    guest_name: "Updated Guest",
    number_of_guests: 6 // from 4
  }
};

// Test Refund with Policy
const refundTest = {
  bookingId: 124,
  data: {
    refund_amount: 7000.00,
    refund_reason: "Customer cancellation",
    override_downpayment_policy: false
  }
  // Expected: Refund ₱7,000, Retain ₱3,000 downpayment
};

// Test Manager Override
const overrideTest = {
  bookingId: 125,
  data: {
    refund_amount: 10000.00,
    refund_reason: "Special case approved by manager",
    override_downpayment_policy: true
  }
  // Expected: Full refund ₱10,000
};

// Test All Extension Types
const extensionTests = [
  { type: 'facility', amount: 1500, qty: 1 },
  { type: 'guest', amount: 350, qty: 2 },
  { type: 'damage', amount: 2500, qty: 1 },
  { type: 'service', amount: 800, qty: 3 }
];
```

---

## 🚀 Quick Start Integration

1. **Update your API service file** with the three new endpoints
2. **Add enum constants** for booking/billing/payment statuses
3. **Create UI components** for each feature (edit modal, refund modal, extension form)
4. **Implement permission checks** based on user role
5. **Test with sample data** using the examples above
6. **Handle errors gracefully** with user-friendly messages

**All backend endpoints are fully tested and production-ready!** ✅

---

## 📝 Notes

- All monetary values are in PHP pesos (₱)
- Decimal values use 2 decimal places
- Dates in `YYYY-MM-DD` format
- Timestamps in `YYYY-MM-DD HH:MM:SS` format
- Bearer token required for all endpoints
- All endpoints return JSON responses
