# Reports Frontend Implementation Guide

This guide provides comprehensive documentation for implementing the Revenue Reports module in the frontend application. It covers all API endpoints, query parameters, response structures, data transformations, and error handling.

---

## Table of Contents

1. [Overview](#overview)
2. [Dashboard Layout](#dashboard-layout)
3. [API Endpoints](#api-endpoints)
4. [Date Presets](#date-presets)
5. [Filters](#filters)
6. [Revenue Report](#revenue-report)
7. [Export Options](#export-options)
8. [Request Examples](#request-examples)
9. [Response Structure](#response-structure)
10. [Error Handling](#error-handling)
11. [Frontend Implementation Tips](#frontend-implementation-tips)

---

## Overview

The Reports module provides revenue analytics with flexible date ranges, filters, and export capabilities. All endpoints require authentication via Sanctum and appropriate permissions.

**Base URL**: `/api/reports`

**Required Permission**: `view-financial-reports` (for viewing reports)  
**Export Permission**: `export-reports` (for exporting to Excel/PDF)

---

## Dashboard Layout

### Simple 6-Metric Dashboard

The revenue reports dashboard displays **6 key metrics** at the top, followed by a transactions table. This simple layout provides all essential financial information at a glance.

```
┌─────────────────────────────────────────────────────────────────┐
│                     REVENUE REPORT DASHBOARD                    │
│                  [Date Filter ▼] [Export Excel/PDF]             │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐         │
│  │   Revenue    │  │    Unpaid    │  │   Expected   │         │
│  │              │  │   Balance    │  │   Revenue    │         │
│  │ ₱125,000.00  │  │  ₱12,000.00  │  │  ₱45,000.00  │         │
│  └──────────────┘  └──────────────┘  └──────────────┘         │
│                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐         │
│  │Non-refundable│  │   Refunds    │  │    Total     │         │
│  │Cancellations │  │              │  │ Transactions │         │
│  │  ₱3,000.00   │  │  ₱2,000.00   │  │      45      │         │
│  └──────────────┘  └──────────────┘  └──────────────┘         │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│                    COMPLETED TRANSACTIONS                       │
│  [Search...] [Type: All ▼]                                      │
├─────────────────────────────────────────────────────────────────┤
│ Date/Time  │ Reference │ Type    │ Guest   │ Amount  │ Method  │
│ Nov 18 5PM │ SWIM-001  │ Walk-in │ John D  │ ₱1,350  │ Cash    │
│ Nov 18 2PM │ BK-042    │ Booking │ Jane S  │ ₱5,000  │ GCash   │
│ ...                                                             │
├─────────────────────────────────────────────────────────────────┤
│                    [Showing 1-10 of 45] [< 1 2 3 >]            │
└─────────────────────────────────────────────────────────────────┘
```

### The 6 Key Metrics Explained

**1. Revenue** 🟢  
Money actually received during the selected period. This includes all completed transactions (both bookings and walk-ins) with fully paid status.

**2. Unpaid Balance** 🟠  
Money owed but not yet collected. Shows unpaid or partially paid billings that need follow-up.

**3. Expected Revenue** 🔵  
Money expected from future confirmed bookings. Calculated from upcoming bookings with unpaid balances.

**4. Non-refundable Cancellations** 🔴  
Forfeited downpayments from cancelled bookings (money kept as cancellation fee).

**5. Refunds** 🟣  
Money returned to customers through refunded transactions during the period.

**6. Total Transactions** ⚪  
Number of completed billing transactions in the period.

### Design Recommendations

**Color Scheme:**
- Revenue: Green (#10B981) - Positive, money in
- Unpaid Balance: Orange (#F59E0B) - Warning, needs attention
- Expected Revenue: Blue (#3B82F6) - Info, future income
- Non-refundable Cancellations: Red (#EF4444) - Negative, forfeited
- Refunds: Purple (#A855F7) - Negative, money returned
- Total Transactions: Gray (#6B7280) - Neutral count

**Metric Cards:**
- Large bold numbers for amounts
- Small label text above
- Icon or emoji for quick visual identification
- Subtle shadow for card depth

---

## API Endpoints

### 1. Get Available Filters
**Endpoint**: `GET /api/reports/filters`  
**Permission**: `view-financial-reports`  
**Purpose**: Retrieve all available filter options for revenue reports

**Response**:
```json
{
  "status": "success",
  "data": {
    "facility_types": [
      {
        "id": 1,
        "type_name": "Cottage"
      },
      {
        "id": 2,
        "type_name": "Pool"
      }
    ],
    "payment_methods": [
      { "value": "cash", "label": "Cash" },
      { "value": "gcash", "label": "GCash" },
      { "value": "bank_transfer", "label": "Bank Transfer" },
      { "value": "credit_card", "label": "Credit Card" },
      { "value": "debit_card", "label": "Debit Card" },
      { "value": "other", "label": "Other" }
    ],
    "staff": [
      {
        "id": 1,
        "name": "Juan Dela Cruz"
      }
    ]
  }
}
```

---

### 2. Get Date Presets
**Endpoint**: `GET /api/reports/date-presets`  
**Permission**: `view-financial-reports`  
**Purpose**: Retrieve pre-defined date range options

**Response**:
```json
{
  "status": "success",
  "data": {
    "today": "Today",
    "yesterday": "Yesterday",
    "this_week": "This Week",
    "last_week": "Last Week",
    "this_month": "This Month",
    "custom": "Custom Range"
  }
}
```

---

### 3. Generate Revenue Report
**Endpoint**: `GET /api/reports/revenue`  
**Permission**: `view-financial-reports`  
**Purpose**: Generate comprehensive revenue report with analytics

**Query Parameters**:

| Parameter | Type | Required | Validation | Description |
|-----------|------|----------|------------|-------------|
| `date_preset` | string | No* | Must be one of: `today`, `yesterday`, `this_week`, `last_week`, `this_month`, `last_month`, `this_quarter`, `last_quarter`, `this_year`, `last_year` | Predefined date range |
| `date_from` | date | No* | Valid date format (Y-m-d) | Start date for custom range |
| `date_to` | date | No* | Valid date, must be >= `date_from` | End date for custom range |
| `facility_type_id` | integer | No | Must exist in `facility_types` table | Filter by facility type |
| `payment_method` | string | No | Must be one of: `cash`, `gcash`, `bank_transfer`, `credit_card`, `debit_card`, `other` | Filter by payment method |
| `staff_id` | integer | No | Must exist in `users` table | Filter by staff member |

**Validation Rules**:
- Either `date_preset` OR both `date_from` and `date_to` must be provided
- If using `date_from`, `date_to` is required
- `date_to` must be equal to or after `date_from`

---

### 4. Export Revenue Report (Excel)
**Endpoint**: `GET /api/reports/revenue/export/excel`  
**Permission**: `export-reports`  
**Purpose**: Download revenue report as Excel file

**Query Parameters**: Same as Revenue Report endpoint

**Response**: Binary Excel file download (`.xlsx`)

**Filename Format**: `revenue_report_{date_from}_{date_to}.xlsx`

---

### 5. Export Revenue Report (PDF)
**Endpoint**: `GET /api/reports/revenue/export/pdf`  
**Permission**: `export-reports`  
**Purpose**: Download revenue report as PDF file

**Query Parameters**: Same as Revenue Report endpoint

**Response**: Binary PDF file download (`.pdf`)

**Filename Format**: `revenue_report_{date_from}_{date_to}.pdf`

---

## Date Presets

The API supports the following date presets:

| Preset | Description | Date Range |
|--------|-------------|------------|
| `today` | Current day | Start of today - End of today |
| `yesterday` | Previous day | Start of yesterday - End of yesterday |
| `this_week` | Current week (Mon-Sun) | Start of week - End of week |
| `last_week` | Previous week | Start of last week - End of last week |
| `this_month` | Current calendar month | First day of month - Last day of month |
| `last_month` | Previous calendar month | First day of last month - Last day of last month |
| `this_quarter` | Current quarter (Q1-Q4) | First day of quarter - Last day of quarter |
| `last_quarter` | Previous quarter | First day of last quarter - Last day of last quarter |
| `this_year` | Current calendar year | January 1 - December 31 |
| `last_year` | Previous calendar year | Jan 1 last year - Dec 31 last year |

**Frontend Usage Example**:
```javascript
// Using preset
const params = { date_preset: 'this_month' };

// Using custom range
const params = {
  date_from: '2024-01-01',
  date_to: '2024-01-31'
};
```

---

## Filters

### Facility Type Filter
Filter revenue by specific facility type (e.g., Cottages, Pools, Rooms).

**Parameter**: `facility_type_id`  
**Type**: Integer  
**Source**: From `/api/reports/filters` endpoint

### Payment Method Filter
Filter revenue by payment method used in transactions.

**Parameter**: `payment_method`  
**Type**: String  
**Allowed Values**:
- `cash`
- `gcash`
- `bank_transfer`
- `credit_card`
- `debit_card`
- `other`

### Staff Filter
Filter revenue by staff member who processed the transaction.

**Parameter**: `staff_id`  
**Type**: Integer  
**Source**: From `/api/reports/filters` endpoint

---

## Revenue Report

### Complete Response Structure

```json
{
  "status": "success",
  "data": {
    "summary": {
      "total_revenue": 125000.00,
      "total_revenue_formatted": "₱125,000.00",
      "booking_revenue": 85000.00,
      "booking_revenue_formatted": "₱85,000.00",
      "booking_percentage": 68.0,
      "guest_entry_revenue": 40000.00,
      "guest_entry_revenue_formatted": "₱40,000.00",
      "guest_entry_percentage": 32.0,
      "expected_revenue": 45000.00,
      "expected_revenue_formatted": "₱45,000.00",
      "expected_count": 12,
      "forfeited_amount": 3000.00,
      "forfeited_amount_formatted": "₱3,000.00",
      "forfeited_count": 2,
      "refunded_amount": 2000.00,
      "refunded_amount_formatted": "₱2,000.00",
      "refunded_count": 3
    },
    "breakdown": {
      "entrance_fees": 15000.00,
      "entrance_fees_formatted": "₱15,000.00",
      "facility_rentals": 95000.00,
      "facility_rentals_formatted": "₱95,000.00",
      "third_party_services": 20000.00,
      "third_party_services_formatted": "₱20,000.00",
      "gross_revenue": 130000.00,
      "gross_revenue_formatted": "₱130,000.00",
      "discounts": 5000.00,
      "discounts_formatted": "₱5,000.00",
      "net_revenue": 125000.00,
      "net_revenue_formatted": "₱125,000.00"
    },
    "by_facility": [
      {
        "facility_name": "Cottage A1",
        "facility_type": "Cottage",
        "revenue": 25000.00,
        "revenue_formatted": "₱25,000.00",
        "percentage": 26.32
      }
    ],
    "by_payment_method": [
      {
        "method": "Cash",
        "count": 45,
        "revenue": 60000.00,
        "revenue_formatted": "₱60,000.00",
        "percentage": 48.0,
        "average": 1333.33
      }
    ],
    "by_source": [
      {
        "source": "Bookings",
        "revenue": 85000.00,
        "revenue_formatted": "₱85,000.00",
        "percentage": 68.0
      },
      {
        "source": "Walk-ins",
        "revenue": 40000.00,
        "revenue_formatted": "₱40,000.00",
        "percentage": 32.0
      }
    ],
    "transactions": [
      {
        "transaction_date": "2025-11-18 17:07:00",
        "transaction_date_formatted": "Nov 18, 2025 05:07 PM",
        "billing_number": "BIL-20251118-001",
        "reference": "SWIM-20251118-001",
        "transaction_type": "Walk-in",
        "guest_name": "John Doe",
        "contact_number": "09123456789",
        "total_amount": 1350.00,
        "total_amount_formatted": "₱1,350.00",
        "amount_paid": 1350.00,
        "amount_paid_formatted": "₱1,350.00",
        "discount_amount": 150.00,
        "discount_amount_formatted": "₱150.00",
        "payment_methods": "Cash",
        "payment_status": "Paid",
        "billing_status": "Completed"
      }
    ],
    "forfeited_downpayments": {
      "total": 5000.00,
      "total_formatted": "₱5,000.00",
      "count": 3
    },
    "outstanding_balances": {
      "total": 12000.00,
      "total_formatted": "₱12,000.00",
      "count": 8
    },
    "comparison": {
      "current_period": 125000.00,
      "current_period_formatted": "₱125,000.00",
      "previous_period": 110000.00,
      "previous_period_formatted": "₱110,000.00",
      "change_amount": 15000.00,
      "change_amount_formatted": "₱15,000.00",
      "change_percentage": 13.64,
      "change_percentage_formatted": "13.64%",
      "direction": "up",
      "period_from": "2023-12-01",
      "period_to": "2023-12-31"
    },
    "period": {
      "from": "2024-01-01",
      "to": "2024-01-31",
      "label": "January 2024"
    }
  }
}
```

### Data Sections Explained

#### 1. Summary
High-level revenue overview with 5 dashboard metrics.

**Key Fields**:
- `total_revenue`: Combined revenue from all completed transactions
- `booking_revenue`: Revenue from advance bookings only
- `guest_entry_revenue`: Revenue from walk-in guests only
- `expected_revenue`: Future revenue from confirmed bookings (unpaid balances)
- `expected_count`: Number of future confirmed bookings
- `forfeited_amount`: Non-refundable cancellation fees (forfeited downpayments)
- `forfeited_count`: Number of cancelled bookings with forfeited amounts
- `refunded_amount`: Money returned to customers through refunds
- `refunded_count`: Number of refunded transactions
- Percentages show distribution between booking vs walk-in revenue

#### 2. Breakdown
Detailed breakdown of revenue components.

**Key Fields**:
- `entrance_fees`: Guest entry fees (walk-ins only)
- `facility_rentals`: Revenue from facility usage (both bookings and walk-ins)
- `third_party_services`: Revenue from additional services
- `gross_revenue`: Total before discounts
- `discounts`: Total discount amount given
- `net_revenue`: Final revenue after discounts

#### 3. By Facility
Revenue breakdown per individual facility, sorted by highest revenue first.

**Key Fields**:
- `facility_name`: Individual facility name (e.g., "Cottage A1")
- `facility_type`: Type category (e.g., "Cottage")
- `revenue`: Total revenue generated by this facility
- `percentage`: % of total facility revenue

#### 4. By Payment Method
Revenue breakdown by payment method used.

**Key Fields**:
- `method`: Payment method name (human-readable)
- `count`: Number of transactions
- `revenue`: Total amount received via this method
- `percentage`: % of total revenue
- `average`: Average transaction amount (revenue / count)

#### 5. By Source
Simple breakdown between Bookings and Walk-ins revenue.

#### 6. Forfeited Downpayments
Revenue from non-refundable cancellations.

**Key Fields**:
- `total`: Total forfeited amount
- `count`: Number of cancelled bookings with forfeited downpayments

#### 7. Outstanding Balances
Current unpaid or partially paid balances (not included in revenue).

**Key Fields**:
- `total`: Total outstanding balance
- `count`: Number of billings with unpaid balance

#### 8. Comparison
Comparison with the previous equivalent period.

**Key Fields**:
- `current_period`: Current period revenue
- `previous_period`: Previous period revenue (auto-calculated)
- `change_amount`: Difference in revenue
- `change_percentage`: Percentage increase/decrease
- `direction`: "up", "down", or "same"

#### 9. Period
Date range information for the report.

**Key Fields**:
- `from`: Start date (Y-m-d format)
- `to`: End date (Y-m-d format)
- `label`: Human-readable period label

#### 10. Transactions
Complete list of all completed billing transactions in the period.

**Key Fields**:
- `transaction_date_formatted`: Human-readable checkout date/time
- `reference`: Booking or Walk-in reference number
- `transaction_type`: "Booking" or "Walk-in"
- `guest_name`: Customer name
- `amount_paid_formatted`: Money collected (formatted)
- `payment_methods`: Payment method(s) used
- `billing_status`: Always "Completed" for these transactions
- `payment_status`: Always "Paid"

**Notes:**
- All transactions shown are fully completed and paid
- Sorted by checkout date (most recent first)
- Used for displaying the transactions table in dashboard

---

## Export Options

### Excel Export Features
- Single sheet with clean, simple layout
- 5 dashboard metrics at the top
- Complete transactions table below
- Formatted headers and styling
- Auto-sized columns

### PDF Export Features
- Company information header
- Period information
- 5 dashboard metrics
- Complete transactions table
- Generated timestamp and user info
- Page size: Letter-size
- Orientation: Portrait

**What's Included in Exports:**
✅ 6 Dashboard Metrics (Revenue, Unpaid Balance, Expected Revenue, Non-refundable Cancellations, Refunds, Total Transactions)  
✅ Complete Transactions Table  
❌ Complex breakdowns (by facility, by payment method) - Removed for simplicity  
❌ Charts and graphs - Removed for simplicity

**Additional PDF Fields**:
```json
{
  "company": {
    "name": "DreamSpace",
    "address": "Brgy. Care, Tarlac City 2300 Philippines",
    "contact": "(+63) 932-358-0889 (Sun) or 910-904-9537 (Smart)",
    "email": "info@abcdreamland.com"
  },
  "generated_at": "January 15, 2024 2:30 PM",
  "generated_by": "Juan Dela Cruz"
}
```

---

## Request Examples

### Example 1: Today's Revenue
```javascript
GET /api/reports/revenue?date_preset=today

// Response: Revenue data for current day
```

### Example 2: Monthly Revenue with Filters
```javascript
GET /api/reports/revenue?date_preset=this_month&facility_type_id=2&payment_method=cash

// Response: This month's revenue for facility type #2, cash payments only
```

### Example 3: Custom Date Range
```javascript
GET /api/reports/revenue?date_from=2024-01-01&date_to=2024-01-31

// Response: Revenue for January 2024
```

### Example 4: Export to Excel
```javascript
GET /api/reports/revenue/export/excel?date_preset=last_month

// Downloads: revenue_report_2023-12-01_2023-12-31.xlsx
```

### Example 5: Staff Performance Report
```javascript
GET /api/reports/revenue?date_preset=this_week&staff_id=5

// Response: This week's revenue processed by staff member #5
```

---

## Error Handling

### Validation Errors (422)
```json
{
  "status": "error",
  "message": "Validation failed",
  "errors": {
    "date_to": ["The date to field must be a date after or equal to date from."],
    "payment_method": ["The selected payment method is invalid."]
  }
}
```

### Server Errors (500)
```json
{
  "status": "error",
  "message": "Failed to generate revenue report",
  "error": "Database connection timeout" // Only in debug mode
}
```

### Permission Denied (403)
```json
{
  "status": "error",
  "message": "Unauthorized"
}
```

### Common Error Scenarios

| Error | Cause | Solution |
|-------|-------|----------|
| `date_from is required` | Neither preset nor date_from provided | Provide either `date_preset` or both `date_from` and `date_to` |
| `date_to is required` | date_from provided without date_to | Always provide both dates for custom range |
| `date_to must be after date_from` | End date before start date | Ensure date_to >= date_from |
| `facility_type_id does not exist` | Invalid facility type ID | Use IDs from `/api/reports/filters` |
| `payment_method is invalid` | Invalid payment method value | Use values from allowed list |
| `Unauthorized` | Missing permission | User needs `view-financial-reports` permission |

---

## Frontend Implementation Tips

### 1. Simple Dashboard with 5 Key Metrics

```javascript
import React from 'react';

const DashboardMetrics = ({ reportData }) => {
  const summary = reportData.summary;
  const outstanding = reportData.outstanding_balances;
  const transactionCount = reportData.transactions?.length || 0;

  return (
    <div className="grid grid-cols-6 gap-4 mb-8">
      {/* Metric 1: Revenue */}
      <MetricCard
        title="Revenue"
        value={summary.total_revenue_formatted}
        color="green"
        icon="💰"
      />

      {/* Metric 2: Unpaid Balance */}
      <MetricCard
        title="Unpaid Balance"
        value={outstanding.total_formatted}
        color="orange"
        icon="⚠️"
        subtitle={`${outstanding.count} accounts`}
      />

      {/* Metric 3: Expected Revenue */}
      <MetricCard
        title="Expected Revenue"
        value={summary.expected_revenue_formatted}
        color="blue"
        icon="📅"
        subtitle={`${summary.expected_count} bookings`}
      />

      {/* Metric 4: Non-refundable Cancellations */}
      <MetricCard
        title="Non-refundable Cancellations"
        value={summary.forfeited_amount_formatted}
        color="red"
        icon="🚫"
        subtitle={`${summary.forfeited_count} cancelled`}
      />

      {/* Metric 5: Refunds */}
      <MetricCard
        title="Refunds"
        value={summary.refunded_amount_formatted}
        color="purple"
        icon="↩️"
        subtitle={`${summary.refunded_count} refunded`}
      />

      {/* Metric 6: Total Transactions */}
      <MetricCard
        title="Total Transactions"
        value={transactionCount}
        color="gray"
        icon="📊"
        isCount={true}
      />
    </div>
  );
};

const MetricCard = ({ title, value, color, icon, subtitle, isCount }) => {
  const colorClasses = {
    green: 'border-green-500 text-green-600',
    orange: 'border-orange-500 text-orange-600',
    blue: 'border-blue-500 text-blue-600',
    red: 'border-red-500 text-red-600',
    purple: 'border-purple-500 text-purple-600',
    gray: 'border-gray-500 text-gray-600',
  };

  return (
    <div className="bg-white rounded-lg shadow-md p-4 border-l-4 ${colorClasses[color]}">
      <div className="flex items-center justify-between mb-2">
        <span className="text-sm text-gray-600 font-medium">{title}</span>
        <span className="text-2xl">{icon}</span>
      </div>
      <div className={`text-3xl font-bold ${colorClasses[color]}`}>
        {value}
      </div>
      {subtitle && (
        <div className="text-xs text-gray-500 mt-1">{subtitle}</div>
      )}
    </div>
  );
};

export default DashboardMetrics;
```

### 2. Transactions Table with Search and Filter

```javascript
import React, { useState } from 'react';

const TransactionsTable = ({ transactions }) => {
  const [searchTerm, setSearchTerm] = useState('');
  const [filterType, setFilterType] = useState('all');
  const [currentPage, setCurrentPage] = useState(1);
  const itemsPerPage = 10;

  // Filter logic
  const filtered = transactions.filter(t => {
    const matchesSearch = 
      t.guest_name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      t.reference.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesType = filterType === 'all' || 
      t.transaction_type.toLowerCase() === filterType;
    return matchesSearch && matchesType;
  });

  // Pagination
  const totalPages = Math.ceil(filtered.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const paginatedData = filtered.slice(startIndex, startIndex + itemsPerPage);

  return (
    <div className="bg-white rounded-lg shadow-md">
      {/* Header with Search and Filter */}
      <div className="p-4 border-b">
        <h2 className="text-xl font-bold mb-4">Completed Transactions</h2>
        <div className="flex gap-4">
          <input
            type="text"
            placeholder="Search by guest name or reference..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="flex-1 px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <select
            value={filterType}
            onChange={(e) => setFilterType(e.target.value)}
            className="px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            <option value="all">All Types</option>
            <option value="booking">Bookings</option>
            <option value="walk-in">Walk-ins</option>
          </select>
        </div>
      </div>

      {/* Table */}
      <div className="overflow-x-auto">
        <table className="min-w-full">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date & Time</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Guest Name</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount Paid</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment Method</th>
            </tr>
          </thead>
          <tbody className="bg-white divide-y divide-gray-200">
            {paginatedData.map((t, index) => (
              <tr key={index} className="hover:bg-gray-50">
                <td className="px-6 py-4 whitespace-nowrap text-sm">
                  {t.transaction_date_formatted}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                  {t.reference}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm">
                  <span className={`px-2 py-1 text-xs font-semibold rounded-full ${
                    t.transaction_type === 'Booking'
                      ? 'bg-blue-100 text-blue-800'
                      : 'bg-green-100 text-green-800'
                  }`}>
                    {t.transaction_type}
                  </span>
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm">
                  {t.guest_name}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm font-semibold text-green-600">
                  {t.amount_paid_formatted}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm">
                  {t.payment_methods}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      <div className="p-4 border-t flex items-center justify-between">
        <div className="text-sm text-gray-700">
          Showing {startIndex + 1} to {Math.min(startIndex + itemsPerPage, filtered.length)} of {filtered.length}
        </div>
        <div className="flex gap-2">
          <button
            onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
            disabled={currentPage === 1}
            className="px-3 py-1 border rounded disabled:opacity-50 hover:bg-gray-50"
          >
            Previous
          </button>
          <span className="px-3 py-1">Page {currentPage} of {totalPages}</span>
          <button
            onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
            disabled={currentPage === totalPages}
            className="px-3 py-1 border rounded disabled:opacity-50 hover:bg-gray-50"
          >
            Next
          </button>
        </div>
      </div>
    </div>
  );
};

export default TransactionsTable;
```

### 3. Complete Dashboard Page

```javascript
import React, { useState, useEffect } from 'react';
import DashboardMetrics from './DashboardMetrics';
import TransactionsTable from './TransactionsTable';

const RevenueReportPage = () => {
  const [reportData, setReportData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [datePreset, setDatePreset] = useState('this_month');

  useEffect(() => {
    loadReport();
  }, [datePreset]);

  const loadReport = async () => {
    setLoading(true);
    try {
      const response = await fetch(`/api/reports/revenue?date_preset=${datePreset}`);
      const data = await response.json();
      
      if (data.status === 'success') {
        setReportData(data.data);
      }
    } catch (error) {
      alert('Failed to load report');
    } finally {
      setLoading(false);
    }
  };

  const exportReport = (format) => {
    window.location.href = `/api/reports/revenue/export/${format}?date_preset=${datePreset}`;
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-screen">
        <div className="text-xl">Loading report...</div>
      </div>
    );
  }

  if (!reportData) {
    return <div>No data available</div>;
  }

  return (
    <div className="min-h-screen bg-gray-100 p-6">
      {/* Header */}
      <div className="mb-6 flex justify-between items-center">
        <div>
          <h1 className="text-3xl font-bold text-gray-900">Revenue Report</h1>
          <p className="text-gray-600 mt-1">{reportData.period.label}</p>
        </div>
        
        <div className="flex gap-4">
          {/* Date Selector */}
          <select
            value={datePreset}
            onChange={(e) => setDatePreset(e.target.value)}
            className="px-4 py-2 border rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            <option value="today">Today</option>
            <option value="yesterday">Yesterday</option>
            <option value="this_week">This Week</option>
            <option value="last_week">Last Week</option>
            <option value="this_month">This Month</option>
            <option value="last_month">Last Month</option>
          </select>

          {/* Export Buttons */}
          <button
            onClick={() => exportReport('excel')}
            className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center gap-2"
          >
            📊 Export Excel
          </button>
          <button
            onClick={() => exportReport('pdf')}
            className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 flex items-center gap-2"
          >
            📄 Export PDF
          </button>
        </div>
      </div>

      {/* 5 Metrics Dashboard */}
      <DashboardMetrics reportData={reportData} />

      {/* Transactions Table */}
      <TransactionsTable transactions={reportData.transactions} />
    </div>
  );
};

export default RevenueReportPage;
```

### 4. Date Range Selector Component
```javascript
// Recommended approach
const [dateMode, setDateMode] = useState('preset'); // 'preset' or 'custom'
const [preset, setPreset] = useState('this_month');
const [dateFrom, setDateFrom] = useState('');
const [dateTo, setDateTo] = useState('');

const buildParams = () => {
  if (dateMode === 'preset') {
    return { date_preset: preset };
  }
  return { date_from: dateFrom, date_to: dateTo };
};
```

### 2. Filter Component
```javascript
// Load filters on mount
useEffect(() => {
  fetch('/api/reports/filters')
    .then(res => res.json())
    .then(data => {
      setFacilityTypes(data.data.facility_types);
      setPaymentMethods(data.data.payment_methods);
      setStaff(data.data.staff);
    });
}, []);
```

### 3. Report Generation
```javascript
const generateReport = async () => {
  const params = new URLSearchParams({
    ...buildParams(),
    ...(selectedFacilityType && { facility_type_id: selectedFacilityType }),
    ...(selectedPaymentMethod && { payment_method: selectedPaymentMethod }),
    ...(selectedStaff && { staff_id: selectedStaff })
  });

  const response = await fetch(`/api/reports/revenue?${params}`);
  const data = await response.json();
  
  if (data.status === 'success') {
    setReportData(data.data);
  }
};
```

### 4. Export Handling
```javascript
const exportReport = (format) => {
  const params = new URLSearchParams({ ...buildParams() });
  const url = `/api/reports/revenue/export/${format}?${params}`;
  
  // Trigger download
  window.location.href = url;
};
```

### 5. Loading and Error States
```javascript
const [loading, setLoading] = useState(false);
const [error, setError] = useState(null);

const generateReport = async () => {
  setLoading(true);
  setError(null);
  
  try {
    const response = await fetch(url);
    const data = await response.json();
    
    if (data.status === 'error') {
      setError(data.message);
    } else {
      setReportData(data.data);
    }
  } catch (err) {
    setError('Failed to generate report');
  } finally {
    setLoading(false);
  }
};

// Display loading state
if (loading) {
  return (
    <div className="flex items-center justify-center p-8">
      <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      <span className="ml-3 text-gray-600">Loading report...</span>
    </div>
  );
}

// Display error state
if (error) {
  return (
    <div className="bg-red-50 border border-red-200 rounded-lg p-4">
      <div className="flex items-center">
        <span className="text-red-600 text-xl mr-2">⚠️</span>
        <div>
          <h3 className="text-red-800 font-semibold">Error Loading Report</h3>
          <p className="text-red-600 text-sm">{error}</p>
        </div>
      </div>
    </div>
  );
}
```

### 6. Revenue Breakdown Chart
```javascript
// Chart data preparation
const prepareBreakdownChart = (breakdown) => ({
  labels: ['Entrance Fees', 'Facility Rentals', 'Third-Party Services'],
  datasets: [{
    data: [
      breakdown.entrance_fees,
      breakdown.facility_rentals,
      breakdown.third_party_services
    ],
    backgroundColor: ['#3B82F6', '#10B981', '#F59E0B']
  }]
});
```

### 7. Facility Performance Table
```javascript
// Facility table component
const FacilityTable = ({ facilities }) => (
  <table>
    <thead>
      <tr>
        <th>Facility Name</th>
        <th>Type</th>
        <th>Revenue</th>
        <th>% of Total</th>
      </tr>
    </thead>
    <tbody>
      {facilities.map(facility => (
        <tr key={facility.facility_name}>
          <td>{facility.facility_name}</td>
          <td>{facility.facility_type}</td>
          <td>{facility.revenue_formatted}</td>
          <td>{facility.percentage.toFixed(2)}%</td>
        </tr>
      ))}
    </tbody>
  </table>
);
```

### 8. Comparison Indicator
```javascript
// Comparison component
const ComparisonIndicator = ({ comparison }) => {
  const icon = comparison.direction === 'up' ? '↑' : 
               comparison.direction === 'down' ? '↓' : '=';
  const color = comparison.direction === 'up' ? 'green' : 
                comparison.direction === 'down' ? 'red' : 'gray';
  
  return (
    <div className={`text-${color}-600`}>
      <span className="text-lg">{icon}</span>
      <span>{comparison.change_percentage_formatted}</span>
      <span>({comparison.change_amount_formatted})</span>
    </div>
  );
};
```

### 9. Loading States
```javascript
const [loading, setLoading] = useState(false);
const [error, setError] = useState(null);

const generateReport = async () => {
  setLoading(true);
  setError(null);
  
  try {
    const response = await fetch(url);
    const data = await response.json();
    
    if (data.status === 'error') {
      setError(data.message);
    } else {
      setReportData(data.data);
    }
  } catch (err) {
    setError('Failed to generate report');
  } finally {
    setLoading(false);
  }
};
```

### 10. Validation Before Submit
```javascript
const validateParams = () => {
  if (dateMode === 'custom') {
    if (!dateFrom || !dateTo) {
      alert('Please select both start and end dates');
      return false;
    }
    if (new Date(dateTo) < new Date(dateFrom)) {
      alert('End date must be after start date');
      return false;
    }
  }
  return true;
};
```

---

## Revenue Recognition Logic

### Important Notes

1. **Revenue is counted based on completion date**, not booking/entry date
2. **Bookings**: Revenue counted when booking status is `Completed` or `Checked_Out` (based on check-out date)
3. **Walk-ins**: Revenue counted when guest entry is checked out (based on checkout date)
4. **Payment Status**: Only `paid` and `partial` payments are included in revenue
5. **Forfeited Downpayments**: Cancelled bookings with paid downpayments are counted as revenue
6. **Outstanding Balances**: Not included in revenue until paid

### Date Range Behavior

- **Bookings**: Uses `check_out_datetime` field
- **Walk-ins**: Uses `checkout_datetime` field
- **Payments**: Filters by `payment_date` for payment method breakdown
- **Comparison**: Automatically calculates equivalent previous period

---

## Recommended UI Flow

1. **Load Filters** → Call `/api/reports/filters` on component mount
2. **Load Date Presets** → Call `/api/reports/date-presets` on component mount
3. **User Selects Filters** → Capture user input (date range, filters)
4. **Generate Report** → Call `/api/reports/revenue` with parameters
5. **Display Results** → Render summary cards, charts, tables
6. **Export Option** → Provide buttons to download Excel/PDF

---

## Quick Reference: Field Mappings

### Summary Section
| Display Name | API Field | Format |
|--------------|-----------|--------|
| Total Revenue | `summary.total_revenue_formatted` | Currency |
| Booking Revenue | `summary.booking_revenue_formatted` | Currency |
| Walk-in Revenue | `summary.guest_entry_revenue_formatted` | Currency |

### Breakdown Section
| Display Name | API Field | Format |
|--------------|-----------|--------|
| Entrance Fees | `breakdown.entrance_fees_formatted` | Currency |
| Facility Rentals | `breakdown.facility_rentals_formatted` | Currency |
| Third-Party Services | `breakdown.third_party_services_formatted` | Currency |
| Gross Revenue | `breakdown.gross_revenue_formatted` | Currency |
| Discounts | `breakdown.discounts_formatted` | Currency |
| Net Revenue | `breakdown.net_revenue_formatted` | Currency |

### Comparison Section
| Display Name | API Field | Format |
|--------------|-----------|--------|
| Current Period | `comparison.current_period_formatted` | Currency |
| Previous Period | `comparison.previous_period_formatted` | Currency |
| Change Amount | `comparison.change_amount_formatted` | Currency |
| Change % | `comparison.change_percentage_formatted` | Percentage |
| Direction | `comparison.direction` | String: "up", "down", "same" |

---

## Notes

- All currency values are provided in both raw (`float`) and formatted (`string`) versions
- Use `_formatted` fields for display purposes
- Use raw numeric fields for calculations or charts
- Percentages are calculated server-side for accuracy
- All dates use `Y-m-d` format (e.g., "2024-01-15")
- Period labels are human-readable (e.g., "January 2024")

---

**End of Guide**
