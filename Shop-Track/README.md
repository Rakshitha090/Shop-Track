# ShopTrack – Smart Small Shop Inventory Management System

ShopTrack is a complete, modern, responsive inventory management and Point-of-Sale (POS) web application designed specifically for small retail shops, convenience stores, and neighborhood merchants.

---

## 🌟 Key Features

### 1. 🛒 Point of Sale (POS) & Checkout
- Real-time catalog search by product name, SKU, or scannable barcode.
- Dynamic cart management with instant line total, subtotal, and tax/discount calculations.
- Cash tender change calculator with quick-fill support for UPI, Credit/Debit cards, and other payment modes.
- Concurrency-safe atomic transaction checkout with row-level locks (`SELECT ... FOR UPDATE`) to eliminate overselling and race conditions.
- Printable receipt modal formatted for thermal and standard receipt printers.

### 2. 📦 Catalog & Product Management
- Complete CRUD management with category association, cost price, retail price, stock level, low-stock reorder thresholds, and optional expiry tracking.
- Automatic inventory status assignment: **In Stock**, **Low Stock**, and **Out of Stock**.
- Safe archiving: products with historical sales are soft-archived to protect the integrity of previous audit invoices.
- Sortable, searchable, and paginated table.

### 3. 🏷️ Categories
- Categorize inventory into departments (Groceries, Beverages, Snacks, Stationery, etc.).
- Active assigned-product counter.
- Safety constraint: prevents accidental deletion of categories containing active products until reassigned.

### 4. 🔄 Stock Control & Movement Audit Log
- Permanent, immutable audit ledger for all inventory shifts.
- Tracks movement types: `sale`, `purchase`, `adjustment_add`, `adjustment_remove`, `initial_stock`, and `sale_cancellation`.
- Captures previous quantity, delta change, closing stock, timestamp, and logged-in user.
- Transactional manual stock adjustment modal with strict negative-stock prevention.

### 5. 🚚 Suppliers & Purchase Replenishment
- Manage vendor contact information, phone numbers, emails, and notes.
- Multi-item stock replenishment workflow: enter supplier invoice/bill number, item quantities, and cost prices.
- Atomic stock increase and unit cost refresh upon receipt.

### 6. 📊 Reports & Financial Analytics
- Date range filtering (Today, Last 7 Days, This Month, All Time, or Custom Range).
- Audited financial metrics: Gross Sales, Total Discounts Given, Net Sales, and Estimated Gross Margin based on recorded purchase costs.
- Visual charts powered by Chart.js (Daily Revenue Bar Chart and Payment Mode Doughnut Chart).
- Current Inventory Asset Valuation (calculated on both supplier cost basis and retail sales value).
- Instant CSV exports for **Sales Invoices** and **Inventory Valuation** with UTF-8 BOM encoding.

### 7. 🔔 Notifications & System Alerts
- Real-time zero-stock and low-stock warnings derived from live database records.
- Recent warehouse activity timeline (incoming purchases and manual adjustments).

### 8. 🛡️ Enterprise Security & Tenancy Isolation
- **Shop-Level Tenancy Isolation:** Every SQL query explicitly binds `shop_id` from the authenticated session, strictly preventing cross-tenant access.
- **Session Security:** `session_regenerate_id(true)` prevents session fixation; cookies configured with `HttpOnly`, `SameSite=Lax`, and `Secure` flags.
- **CSRF Defense:** Cryptographic token verification on all POST/mutation endpoints.
- **Rate Limiting:** Protects against brute-force password guessing by locking out repeated failures for 5 minutes.
- **Password Security:** Hashes stored using PHP's `password_hash()` (Bcrypt).
- **XSS Sanitization:** Output escaped via `h()` utility throughout all templates.

---

## 🛠️ Technology Stack

- **Frontend:** HTML5, CSS3 (Modern SaaS design system, Inter typography, responsive sidebar/drawer), Vanilla JavaScript (ES6+).
- **Icons & Charts:** Lucide Icons, Chart.js.
- **Backend:** PHP 8.2+ (pure vanilla PHP, zero heavy framework dependencies).
- **Database:** Supabase PostgreSQL with `pdo_pgsql` driver.

---

## 🚀 Quick Start Guide

### 1. Requirements
Ensure PHP 8.2 or later is installed with the following extensions enabled in `php.ini`:
- `pdo`
- `pdo_pgsql`
- `pgsql`
- `openssl`
- `mbstring`

### 2. Configure Database in Supabase
1. Log in to your [Supabase Dashboard](https://supabase.com).
2. Create a new project or select an existing one.
3. Open the **SQL Editor** from the left navigation.
4. Copy the entire contents of [`database/schema.sql`](database/schema.sql) and paste it into the SQL Editor.
5. Click **Run** to generate the required tables, triggers, and performance indexes.

### 3. Configure Environment Variables
1. Copy `.env.example` to `.env`:
   ```bash
   cp .env.example .env
   ```
2. Open `.env` and fill in your Supabase connection parameters (found in your Supabase Project Settings -> Database -> Connection Parameters):
   ```env
   APP_ENV=development
   APP_NAME=ShopTrack
   APP_URL=http://localhost:8000

   SUPABASE_DB_HOST=db.your-project-ref.supabase.co
   SUPABASE_DB_PORT=5432
   SUPABASE_DB_NAME=postgres
   SUPABASE_DB_USER=postgres
   SUPABASE_DB_PASSWORD=your_actual_db_password
   SUPABASE_DB_SSLMODE=require
   ```

### 4. Run the Local PHP Server
Start the built-in PHP web server:
```bash
php -S localhost:8000
```
Open your web browser and navigate to:
```
http://localhost:8000
```

### 5. Create Your First Shop Account
1. Click **Register your shop** on the login screen.
2. Enter your Full Name, Store Name, Email Address, and Password (minimum 8 characters).
3. Upon registration, your shop domain and owner account are created in Supabase with starter categories (`General Items`, `Beverages & Snacks`, `Groceries`).
4. Log in with your new credentials and start adding products!

---

## 🧪 Running Automated Tests

Run the built-in unit and diagnostic test suite:
```bash
php tests/test_auth.php
php tests/test_business_rules.php
php tests/test_database.php
```

---

## 📂 Project Structure

```
├── index.php                  # Main Dashboard with metrics & 7-day sales chart
├── login.php                  # Secure authentication with rate limiting
├── register.php               # Shop & owner registration transaction
├── logout.php                 # Secure session destruction
├── products.php               # Searchable, filterable product catalog table
├── product-form.php           # Unified Add / Edit Product form
├── categories.php             # Category management with product count badges
├── sales.php                  # Point of Sale (POS) checkout interface
├── sales-history.php          # Invoice history, reprint, and refund reversals
├── stock.php                  # Stock movements ledger & adjustment modal
├── suppliers.php              # Vendor management
├── purchases.php              # Stock replenishment & supplier billing
├── reports.php                # Financial analytics, Chart.js graphs & CSV exports
├── notifications.php          # Out-of-stock and low-stock real-time alerts
├── settings.php               # Store branding, currency, and password change
├── profile.php                # User profile information
├── 403.php                    # Forbidden error page
├── 404.php                    # Not found error page
├── config/
│   ├── app.php                # Application constants & session bootstrap
│   └── database.php           # Supabase PostgreSQL PDO connection singleton
├── includes/
│   ├── auth.php               # Authentication state & tenancy guards
│   ├── csrf.php               # Anti-CSRF token verification
│   ├── functions.php          # Formatting, helpers, and stock audit logging
│   ├── header.php             # Topbar, breadcrumb, and navigation head
│   ├── sidebar.php            # Left sidebar navigation with mobile toggle
│   └── footer.php             # Script tags, modal containers, toast container
├── api/
│   ├── products/              # Product search, details, archive
│   ├── categories/            # Category CRUD
│   ├── sales/                 # Atomic POS checkout & sale cancellation
│   ├── purchases/             # Multi-item stock replenishment
│   ├── suppliers/             # Supplier CRUD
│   ├── stock/                 # Manual inventory adjustments
│   ├── reports/               # CSV export handlers
│   └── settings/              # Settings JSON endpoint
├── assets/
│   ├── css/
│   │   ├── style.css          # Main SaaS design system stylesheet
│   │   └── responsive.css     # Mobile drawer and responsive layout rules
│   └── js/
│       ├── app.js             # Modals, toasts, CSRF AJAX helper
│       ├── sales.js           # POS cart, calculations, and receipt generator
│       ├── dashboard.js       # Dashboard helpers
│       ├── products.js        # Product catalog helpers
│       └── reports.js         # Report interactions
├── database/
│   └── schema.sql             # Complete Supabase PostgreSQL schema DDL
├── tests/
│   ├── test_auth.php          # Unit tests for security & session tokens
│   ├── test_business_rules.php# Unit tests for currency & stock statuses
│   └── test_database.php      # Driver and connection diagnostic tests
├── .env.example               # Configuration template
├── .gitignore                 # Sensitive file exclusion
└── README.md                  # Comprehensive setup & architecture documentation
```

---

## 🔒 Security Best Practices

1. **Database Credentials:** Never commit `.env` or database passwords to version control.
2. **Tenancy:** Never remove `shop_id` filtering from SQL queries or trust browser-submitted IDs.
3. **Session Cookies:** When deploying to production behind an SSL certificate, ensure `session.cookie_secure` is enabled (configured automatically when accessed over HTTPS).
