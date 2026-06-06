# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Open Source Point of Sale (OSPOS) — a web-based POS system built on **CodeIgniter 4** (v4.6.3+) with PHP 8.1–8.4, MySQL/MariaDB, and a Bootstrap 3 frontend with jQuery.

## Common Commands

### Build (frontend assets)
```bash
composer install
npm install
npm run build          # runs gulp default task
```

### Run Tests
```bash
vendor/bin/phpunit                           # all tests
vendor/bin/phpunit tests/helpers/            # single directory
vendor/bin/phpunit --filter UrlHelperTest    # single test class
```
Tests require a MySQL database. Configure via `phpunit.xml.dist` env vars or `app/Config/Database.php` (`tests` group). Default credentials: admin/pointofsale, database: ospos.

### Code Style
```bash
# Check coding standards (dry-run)
vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.no-header.php

# Auto-fix coding standards
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.no-header.php
```
Uses CodeIgniter 4 coding standard. CI runs this against PHP 8.1–8.4.

### Docker Development
```bash
export USERID=$(id -u) && export GROUPID=$(id -g)
docker-compose -f docker-compose.dev.yml up
```

### CodeIgniter CLI
```bash
php spark migrate        # run database migrations
php spark db:seed        # run database seeds
```

## Architecture

### MVC Structure
```
app/
├── Controllers/    # HTTP controllers (28+ files)
├── Models/         # Data models (34+ files)
│   ├── Reports/    # 23 report-specific models
│   ├── Tokens/     # 12 token/sequence models
│   └── Enums/      # Enum types
├── Views/          # PHP templates, organized by domain
├── Libraries/      # Business logic (Sale_lib, Tax_lib, Receiving_lib, etc.)
├── Helpers/        # Helper functions (locale, tabular, tax, security, etc.)
├── Config/         # Framework and app configuration
├── Database/       # Migrations (81 files), seeds, SQL schemas
├── Language/       # 49 language directories (i18n via Weblate)
└── Filters/        # HTTP middleware (CSRF, honeypot, security headers)
public/             # Web root — entry point is public/index.php
tests/              # PHPUnit tests
```

### Controller Hierarchy
- `BaseController` — CodeIgniter base
  - **Public controllers**: `Login`, `Home`, `Messages`, `No_access`
  - `Secure_Controller` — enforces authentication + module-level permissions
    - All protected controllers: `Sales`, `Items`, `Customers`, `Employees`, `Reports`, `Receivings`, `Expenses`, `Config`, etc.

`Secure_Controller` checks `is_logged_in()` and `has_module_grant()` in its constructor. Every protected controller passes a `$module_id` to `Secure_Controller::__construct()`.

### Model Hierarchy
- `Person` is the base model for `Employee`, `Customer`, and `Supplier`
- Models use CI4 conventions: `$table`, `$primaryKey`, `$allowedFields`
- All tables are prefixed with `ospos_`
- Standard model methods: `get_all()`, `exists()`, `get_info()`, `get_total_rows()`

### Business Logic Libraries
Complex logic lives in `app/Libraries/`, not controllers:
- **Sale_lib** (53KB) — sales transactions, cart, payments
- **Tax_lib** (21KB) — multi-tier tax calculations (VAT/GST, tax-included/excluded modes)
- **Receiving_lib** (16KB) — purchase receiving
- **Barcode_lib**, **Email_lib**, **Sms_lib**, **Mailchimp_lib**, **Token_lib**

### Configuration System
- `app/Config/OSPOS.php` — loads app settings from DB, caches them in memory
- `app/Config/Constants.php` — domain constants (sale types, item types, payment types, etc.)
- `.env` file for environment-specific overrides (DB credentials, encryption key)

### Routes
Defined in `app/Config/Routes.php`. Default controller is `Login`. Uses pattern-based routing with CI4 placeholders (e.g., `(:any)`, `(:num)`). No REST API — this is a traditional server-rendered app with AJAX enhancements.

### Frontend
- Bootstrap 3 + Bootswatch themes (configurable per-installation)
- jQuery 3.7.1, bootstrap-table, bootstrap-select, Chart.js/Chartist
- jsPDF + jspdf-autotable for client-side PDF generation
- DOMPurify for XSS protection on the client side
- Assets built by Gulp into `public/resources/`

### Database
- MySQL/MariaDB with `ospos_` table prefix
- 81 timestamped migrations in `app/Database/Migrations/`
- Schema defined in `app/Database/sqlscripts/tables.sql` and `constraints.sql`

## Key Conventions

- PHP code follows CodeIgniter 4 coding standard (enforced by php-cs-fixer)
- Models instantiated via `model(ClassName::class)`, config via `config(OSPOS::class)`
- Session-based authentication; module-based authorization via `Module` model grants
- Two menu groups: `home` (POS interface) and `office` (admin/back-office)
- Views receive shared data through `$this->global_view_data` set in `Secure_Controller`
- Internationalization: 49 locales, managed via Weblate; language files in `app/Language/`
