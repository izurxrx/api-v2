# Billing Extensions - Frontend Implementation Guide

## 📖 Overview

The extension system allows adding charges to existing guest entries and bookings during their stay. This includes:
- **Facility rentals** (cottages, rooms, kayaks, etc.)
- **Guest additions** (extra entrance fees for walk-ins/swimming)
- **Third-party services** (massage, catering, tours)
- **Damage/custom charges** (broken items, special requests)

**Key Features:**
- ✅ No discounts on extensions (discounts only at booking/entry creation)
- ✅ Entry type restrictions (walk-in, Swimming, Package)
- ✅ Auto-inherits hours for walk-in facility extensions
- ✅ Auto-fetches per-guest rates from metadata
- ✅ Optional payment recording
- ✅ Real-time billing total updates

---

## 🔐 Authorization

**Required:** Bearer Token (Staff/Manager/Admin)

**Endpoint:** `POST /api/billings/{billing_id}/extensions`

---

## 🎯 Entry Type Restrictions

Before showing extension options, check the `extension_restrictions` from the billing resource:

```json
{
  "extension_restrictions": {
    "can_add_facilities": true,
    "can_add_guests": false,
    "can_add_services": true,
    "can_add_overtime": false,
    "entry_type": "booking",
    "booking_type": "Package"
  }
}
```

### Restriction Rules

| Entry Type | Can Add Facilities | Can Add Guests | Can Add Services | Can Add Overtime |
|------------|-------------------|----------------|------------------|------------------|
| **Walk-in** | ✅ Yes | ✅ Yes | ✅ Yes | ❌ No |
| **Swimming Booking** | ❌ No | ✅ Yes | ✅ Yes | ❌ No |
| **Package Booking** | ✅ Yes | ❌ No | ✅ Yes | ✅ Yes* |

*Overtime is calculated automatically at checkout (opt-in), not added as extension manually.

---

## 📋 API Request Structure

### Smart Mode (Facilities, Guests, Services)

```json
{
  "facilities": [
    {
      "facility_id": 5,
      "rate_id": 12,
      "quantity": 2,
      "hours": 4
    }
  ],
  "guest_charges": [
    {
      "guest_type": "Adult",
      "count": 2,
      "rate_per_guest": 150.00
    },
    {
      "guest_type": "Child",
      "count": 1,
      "rate_per_guest": 100.00
    }
  ],
  "third_party_services": [
    {
      "service_name": "Massage Therapy",
      "amount": 500.00
    }
  ],
  "amount_paid": 1000.00,
  "payment_method": "Cash"
}
```

**Field Notes:**
- `facilities[].hours`: Optional for walk-ins (auto-inherited from original entry)
- `guest_charges[].rate_per_guest`: Optional (auto-fetched from billing metadata)
- `amount_paid`: Optional payment to record
- `payment_method`: Required if `amount_paid > 0`

### Simple Mode (Damage/Custom Charges)

```json
{
  "simple_mode": true,
  "amount": 250.00,
  "quantity": 1,
  "amount_paid": 250.00,
  "payment_method": "Cash"
}
```

---

## ✅ Success Response (200)

```json
{
  "message": "Extension added successfully",
  "success": true,
  "data": {
    "billing": {
      "id": 123,
      "billing_number": "BL-20251209-001",
      "subtotal": "5000.00",
      "total_amount": "6500.00",
      "amount_paid": "1000.00",
      "balance": "5500.00",
      "payment_status": "partial",
      "entry_type": "walk_in",
      "per_guest_rates": {
        "adult": 150.00,
        "child": 100.00
      },
      "extension_restrictions": {
        "can_add_facilities": true,
        "can_add_guests": true,
        "can_add_services": true,
        "can_add_overtime": false
      }
    },
    "extensions": [
      {
        "id": 45,
        "extension_type": "facility",
        "amount": "300.00",
        "quantity": 2,
        "hours": 4,
        "total_amount": "2400.00",
        "metadata": {
          "facility_id": 5,
          "facility_name": "Cottage A",
          "rate_id": 12,
          "rate_type": "Hourly"
        }
      }
    ],
    "extension_summary": {
      "total_extension_amount": "1500.00",
      "final_extension_amount": "1500.00",
      "detailed_calculations": [
        {
          "type": "facility",
          "facility": "Cottage A",
          "rate": "Hourly",
          "amount": "300.00",
          "hours": 4,
          "quantity": 2,
          "subtotal": "2400.00"
        },
        {
          "type": "guest",
          "guest_type": "Adult",
          "rate_per_guest": "150.00",
          "count": 2,
          "subtotal": "300.00"
        }
      ]
    },
    "payment": {
      "id": 78,
      "amount": "1000.00",
      "payment_method": "Cash",
      "payment_date": "2025-12-09T14:30:00"
    }
  }
}
```

---

## ❌ Error Responses

### 400: Entry Type Restriction
```json
{
  "message": "Cannot add facilities to Swimming bookings.",
  "success": false
}
```

### 400: Missing Rate
```json
{
  "message": "Rate not found for guest type: Senior. Please provide rate_per_guest.",
  "success": false
}
```

### 404: Billing Not Found
```json
{
  "message": "Billing not found",
  "success": false
}
```

### 422: Validation Errors
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "facilities.0.facility_id": ["The facility id field is required."],
    "guest_charges.0.count": ["The count must be at least 1."],
    "payment_method": ["The payment method field is required when amount paid is present."]
  }
}
```

---

## 🎨 Frontend Implementation Examples

### React/TypeScript Example

```typescript
import { useState } from 'react';
import axios from 'axios';

interface ExtensionRestrictions {
  can_add_facilities: boolean;
  can_add_guests: boolean;
  can_add_services: boolean;
  can_add_overtime: boolean;
  entry_type: string;
  booking_type?: string;
}

interface Billing {
  id: number;
  total_amount: string;
  balance: string;
  extension_restrictions: ExtensionRestrictions;
  per_guest_rates?: Record<string, number>;
}

interface FacilityExtension {
  facility_id: number;
  rate_id: number;
  quantity: number;
  hours?: number;
}

interface GuestCharge {
  guest_type: string;
  count: number;
  rate_per_guest?: number;
}

interface Service {
  service_name: string;
  amount: number;
}

const AddExtensionForm = ({ billing }: { billing: Billing }) => {
  const [facilities, setFacilities] = useState<FacilityExtension[]>([]);
  const [guests, setGuests] = useState<GuestCharge[]>([]);
  const [services, setServices] = useState<Service[]>([]);
  const [amountPaid, setAmountPaid] = useState<number>(0);
  const [paymentMethod, setPaymentMethod] = useState<string>('Cash');
  const [loading, setLoading] = useState(false);

  const restrictions = billing.extension_restrictions;

  const handleAddExtension = async () => {
    setLoading(true);
    try {
      const payload = {
        facilities: restrictions.can_add_facilities ? facilities : undefined,
        guest_charges: restrictions.can_add_guests ? guests : undefined,
        third_party_services: restrictions.can_add_services ? services : undefined,
        amount_paid: amountPaid,
        payment_method: amountPaid > 0 ? paymentMethod : undefined,
      };

      const response = await axios.post(
        `/api/billings/${billing.id}/extensions`,
        payload
      );

      alert('Extension added successfully!');
      console.log('Updated billing:', response.data.data.billing);
      
      // Reset form or redirect
      window.location.reload();
    } catch (error: any) {
      if (error.response?.status === 400) {
        alert(error.response.data.message);
      } else if (error.response?.status === 422) {
        alert('Validation error: ' + JSON.stringify(error.response.data.errors));
      } else {
        alert('Failed to add extension');
      }
    } finally {
      setLoading(false);
    }
  };

  const addFacility = () => {
    setFacilities([...facilities, { facility_id: 0, rate_id: 0, quantity: 1 }]);
  };

  const addGuest = () => {
    setGuests([...guests, { guest_type: 'Adult', count: 1 }]);
  };

  const addService = () => {
    setServices([...services, { service_name: '', amount: 0 }]);
  };

  return (
    <div className="extension-form">
      <h2>Add Extension to Billing #{billing.id}</h2>
      <p>Current Balance: ₱{billing.balance}</p>

      {/* Facility Extensions */}
      {restrictions.can_add_facilities && (
        <div className="section">
          <h3>Facilities</h3>
          {facilities.map((facility, index) => (
            <div key={index} className="facility-row">
              <select
                value={facility.facility_id}
                onChange={(e) => {
                  const updated = [...facilities];
                  updated[index].facility_id = Number(e.target.value);
                  setFacilities(updated);
                }}
              >
                <option value={0}>Select Facility</option>
                {/* Load from API */}
              </select>
              
              <input
                type="number"
                placeholder="Quantity"
                min={1}
                value={facility.quantity}
                onChange={(e) => {
                  const updated = [...facilities];
                  updated[index].quantity = Number(e.target.value);
                  setFacilities(updated);
                }}
              />

              {restrictions.entry_type !== 'walk_in' && (
                <input
                  type="number"
                  placeholder="Hours"
                  min={1}
                  value={facility.hours || 1}
                  onChange={(e) => {
                    const updated = [...facilities];
                    updated[index].hours = Number(e.target.value);
                    setFacilities(updated);
                  }}
                />
              )}
            </div>
          ))}
          <button onClick={addFacility}>+ Add Facility</button>
        </div>
      )}

      {/* Guest Charges */}
      {restrictions.can_add_guests && (
        <div className="section">
          <h3>Additional Guests</h3>
          {guests.map((guest, index) => (
            <div key={index} className="guest-row">
              <select
                value={guest.guest_type}
                onChange={(e) => {
                  const updated = [...guests];
                  updated[index].guest_type = e.target.value;
                  // Auto-fill rate from metadata
                  const rate = billing.per_guest_rates?.[e.target.value.toLowerCase()];
                  if (rate) updated[index].rate_per_guest = rate;
                  setGuests(updated);
                }}
              >
                <option>Adult</option>
                <option>Child</option>
                <option>Senior</option>
              </select>

              <input
                type="number"
                placeholder="Count"
                min={1}
                value={guest.count}
                onChange={(e) => {
                  const updated = [...guests];
                  updated[index].count = Number(e.target.value);
                  setGuests(updated);
                }}
              />

              <input
                type="number"
                placeholder="Rate per guest"
                value={guest.rate_per_guest || ''}
                onChange={(e) => {
                  const updated = [...guests];
                  updated[index].rate_per_guest = Number(e.target.value);
                  setGuests(updated);
                }}
              />
            </div>
          ))}
          <button onClick={addGuest}>+ Add Guest</button>
        </div>
      )}

      {/* Third-party Services */}
      {restrictions.can_add_services && (
        <div className="section">
          <h3>Services</h3>
          {services.map((service, index) => (
            <div key={index} className="service-row">
              <input
                type="text"
                placeholder="Service name"
                value={service.service_name}
                onChange={(e) => {
                  const updated = [...services];
                  updated[index].service_name = e.target.value;
                  setServices(updated);
                }}
              />

              <input
                type="number"
                placeholder="Amount"
                min={0}
                value={service.amount}
                onChange={(e) => {
                  const updated = [...services];
                  updated[index].amount = Number(e.target.value);
                  setServices(updated);
                }}
              />
            </div>
          ))}
          <button onClick={addService}>+ Add Service</button>
        </div>
      )}

      {/* Payment */}
      <div className="section">
        <h3>Payment (Optional)</h3>
        <input
          type="number"
          placeholder="Amount paid"
          min={0}
          value={amountPaid}
          onChange={(e) => setAmountPaid(Number(e.target.value))}
        />

        {amountPaid > 0 && (
          <select value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)}>
            <option>Cash</option>
            <option>GCash</option>
            <option>Bank Transfer</option>
            <option>Credit Card</option>
          </select>
        )}
      </div>

      <button onClick={handleAddExtension} disabled={loading}>
        {loading ? 'Adding...' : 'Add Extension'}
      </button>
    </div>
  );
};

export default AddExtensionForm;
```

---

## 🎯 Simple Mode (Damage Charges)

For quick damage/custom charges without itemization:

```typescript
const AddDamageCharge = ({ billingId }: { billingId: number }) => {
  const [amount, setAmount] = useState<number>(0);
  const [quantity, setQuantity] = useState<number>(1);
  const [loading, setLoading] = useState(false);

  const handleAddDamage = async () => {
    setLoading(true);
    try {
      await axios.post(`/api/billings/${billingId}/extensions`, {
        simple_mode: true,
        amount,
        quantity,
      });

      alert('Damage charge added!');
      window.location.reload();
    } catch (error) {
      alert('Failed to add damage charge');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div>
      <h3>Add Damage Charge</h3>
      <input
        type="number"
        placeholder="Amount per item"
        value={amount}
        onChange={(e) => setAmount(Number(e.target.value))}
      />
      <input
        type="number"
        placeholder="Quantity"
        min={1}
        value={quantity}
        onChange={(e) => setQuantity(Number(e.target.value))}
      />
      <p>Total: ₱{(amount * quantity).toFixed(2)}</p>
      <button onClick={handleAddDamage} disabled={loading}>
        Add Charge
      </button>
    </div>
  );
};
```

---

## 📊 Display Extension History

Fetch extensions from billing resource:

```typescript
const ExtensionHistory = ({ billing }: { billing: Billing }) => {
  return (
    <div className="extension-history">
      <h3>Extension History</h3>
      <table>
        <thead>
          <tr>
            <th>Type</th>
            <th>Description</th>
            <th>Amount</th>
            <th>Qty/Hours</th>
            <th>Total</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          {billing.extensions?.map((ext) => (
            <tr key={ext.id}>
              <td>{ext.extension_type}</td>
              <td>
                {ext.extension_type === 'facility' && ext.metadata?.facility_name}
                {ext.extension_type === 'guest' && `${ext.metadata?.guest_type} Guest`}
                {ext.extension_type === 'service' && ext.metadata?.service_name}
                {ext.extension_type === 'damage' && 'Damage/Custom Charge'}
              </td>
              <td>₱{ext.amount}</td>
              <td>
                {ext.quantity}
                {ext.hours && ` × ${ext.hours}h`}
              </td>
              <td>₱{ext.total_amount}</td>
              <td>{new Date(ext.created_at).toLocaleString()}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};
```

---

## ✅ Best Practices

### 1. Always Check Restrictions
```typescript
if (!billing.extension_restrictions.can_add_facilities) {
  // Hide facility section or show disabled message
  return <p>Cannot add facilities to this booking type.</p>;
}
```

### 2. Auto-fill Rates from Metadata
```typescript
const guestRate = billing.per_guest_rates?.[guestType.toLowerCase()];
if (guestRate) {
  // Pre-fill the rate field
  setRatePerGuest(guestRate);
}
```

### 3. Walk-in Hour Inheritance
```typescript
// For walk-ins, hours field should be disabled or hidden
{billing.entry_type === 'walk_in' ? (
  <p>Hours: Auto-inherited from original entry</p>
) : (
  <input type="number" placeholder="Hours" />
)}
```

### 4. Real-time Total Calculation
```typescript
const calculateTotal = () => {
  let total = 0;
  
  facilities.forEach(f => {
    total += (f.amount || 0) * (f.hours || 1) * (f.quantity || 1);
  });
  
  guests.forEach(g => {
    total += (g.rate_per_guest || 0) * (g.count || 0);
  });
  
  services.forEach(s => {
    total += s.amount || 0;
  });
  
  return total;
};
```

### 5. Payment Validation
```typescript
if (amountPaid > 0 && !paymentMethod) {
  alert('Please select a payment method');
  return;
}

if (amountPaid > billing.balance) {
  alert('Payment amount exceeds balance');
  return;
}
```

---

## 🔍 Testing Checklist

- [ ] Walk-in: Can add facilities, guests, services
- [ ] Swimming booking: Can add guests only (facilities/overtime disabled)
- [ ] Package booking: Can add facilities, services (guests disabled)
- [ ] Hours auto-inherit for walk-in facility extensions
- [ ] Per-guest rates auto-fetch from metadata
- [ ] Optional payment records correctly
- [ ] Billing totals update in real-time
- [ ] Validation errors display clearly
- [ ] Extension history displays all charges
- [ ] Cannot add discounts (fields should not exist)
- [ ] Simple mode works for damage charges

---

## 🎨 UI/UX Recommendations

### Conditional Rendering
Only show sections that are allowed based on restrictions:

```typescript
{restrictions.can_add_facilities && <FacilitySection />}
{restrictions.can_add_guests && <GuestSection />}
{restrictions.can_add_services && <ServiceSection />}
```

### Visual Indicators
Show what's allowed/restricted:

```html
<div className="restriction-badge">
  {restrictions.entry_type === 'walk_in' && (
    <span className="badge badge-info">Walk-in Entry</span>
  )}
  {restrictions.booking_type === 'Swimming' && (
    <span className="badge badge-warning">Swimming Booking - Limited Extensions</span>
  )}
  {restrictions.booking_type === 'Package' && (
    <span className="badge badge-success">Package Booking</span>
  )}
</div>
```

### Calculation Preview
Show live calculation before submitting:

```typescript
const ExtensionPreview = ({ facilities, guests, services }) => {
  const facilityTotal = facilities.reduce((sum, f) => 
    sum + (f.amount * f.quantity * (f.hours || 1)), 0
  );
  const guestTotal = guests.reduce((sum, g) => 
    sum + (g.rate_per_guest * g.count), 0
  );
  const serviceTotal = services.reduce((sum, s) => 
    sum + s.amount, 0
  );
  const grandTotal = facilityTotal + guestTotal + serviceTotal;

  return (
    <div className="preview">
      <h4>Extension Preview</h4>
      {facilityTotal > 0 && <p>Facilities: ₱{facilityTotal.toFixed(2)}</p>}
      {guestTotal > 0 && <p>Guests: ₱{guestTotal.toFixed(2)}</p>}
      {serviceTotal > 0 && <p>Services: ₱{serviceTotal.toFixed(2)}</p>}
      <hr />
      <p><strong>Total: ₱{grandTotal.toFixed(2)}</strong></p>
    </div>
  );
};
```

---

## 📞 Support & Troubleshooting

### Common Issues

**Issue:** "Cannot add facilities to Swimming bookings"  
**Solution:** Check `extension_restrictions.can_add_facilities` before showing facility section.

**Issue:** "Rate not found for guest type"  
**Solution:** Either provide `rate_per_guest` manually or ensure billing has `per_guest_rates` metadata.

**Issue:** Validation error on payment_method  
**Solution:** Only send `payment_method` when `amount_paid > 0`.

**Issue:** Hours field required for Package bookings  
**Solution:** For bookings (not walk-ins), always include `hours` field in facility extensions.

---

## 📚 Related Endpoints

- `GET /api/billings/{id}` - Fetch billing with extension restrictions
- `GET /api/facilities` - List available facilities
- `GET /api/rates` - List facility rates
- `POST /api/billings/{id}/payments` - Record standalone payment

---

## 📝 Changelog

**v2.0 - December 9, 2025**
- Initial implementation of extension system
- Removed discount functionality from extensions
- Added entry type restrictions
- Added metadata-based rate fetching
- Added auto-hour inheritance for walk-ins

---

**API Version:** 2.0  
**Last Updated:** December 9, 2025  
**Maintained by:** Resort Management API Team
