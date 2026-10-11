# Supervisor Approval for Returns — Design

**Date:** 2026-10-11
**Status:** Approved in conversation; awaiting spec review

## Problem

Any employee with access to the Sales register can complete a transaction containing
a negative-quantity line, either via Return mode or by typing a negative quantity in
Sale mode. Completing such a sale adds stock back and pays money out. There is no
permission gating this and no approval step, which makes fake refunds an easy theft
vector.

## Goal

Returns must still be possible at the till, but a cashier without authority cannot
complete one alone. A supervisor approves at the till by entering their own
credentials, **without logging the cashier out**. The approver is recorded and visible
in reports.

### Agreed decisions

- "Supervisor" = any employee granted the new `sales_returns` permission.
- Approval happens **once per transaction, at checkout** (not per line).
- Approver is **stored on the sale and shown in the Detailed Sales report**; not printed
  on receipts.
- Threat model: prevent a cashier acting alone. Collusion with a supervisor is out of scope.

### Non-goals

- Rate-limiting failed approval attempts (login page has none either).
- Approval for editing/deleting past sales (already governed by `sales_delete`).
- Printing the approver on receipts.
- Translating new strings beyond `en` (other locales come through Weblate).

## Design

### 1. Permission

New migration `app/Database/Migrations/20261011000000_SalesReturnsPermission.php`
running `sqlscripts/3.4.3_sales_returns_permission.sql`, mirroring
`3.3.2_saleschangeprice.sql`:

```sql
INSERT INTO `ospos_permissions` (`permission_id`, `module_id`) VALUES ('sales_returns', 'sales');
INSERT INTO `ospos_grants` (`permission_id`, `person_id`, `menu_group`) VALUES ('sales_returns', 1, '--');
```

The permission appears under Employees → Permissions alongside `sales_change_price`
and `sales_delete`. Label added to `app/Language/en/` following the same convention
as those permissions. `down()` deletes the grants and the permission row.

### 2. Data

Same migration adds a nullable column:

```sql
ALTER TABLE `ospos_sales` ADD COLUMN `return_approved_by` INT(10) NULL DEFAULT NULL,
  ADD CONSTRAINT `ospos_sales_ibfk_return_approved_by`
  FOREIGN KEY (`return_approved_by`) REFERENCES `ospos_employees` (`person_id`);
```

- Add `return_approved_by` to `Sale::$allowedFields`.
- `Sale::save_value()` gains a trailing optional parameter `?int $return_approved_by = null`
  written into `$sales_data`. Existing callers are unaffected.
- `NULL` means no approval was needed (no return lines, or cashier holds `sales_returns`).

### 3. When approval is required

A new library `App\Libraries\Return_approval` (unit-testable with a mocked `Employee`)
provides `requires_approval(array $cart, int $employee_id): bool`, which returns true when:

- the cart has at least one line with `quantity < 0`, **and**
- the employee does **not** hold `sales_returns`.

Checked only when **completing** a transaction (`Sales::postComplete`). Building the
cart, suspending, and cancelling are unaffected — none of them move stock or cash.
A suspended sale with return lines needs approval when later completed.

### 4. Approval verification (server-side — the actual protection)

At the start of `Sales::postComplete`, before any `save_value()` call:

1. If `Return_approval::requires_approval()` is false → proceed as today, approver `null`.
2. Otherwise read `approver_username` / `approver_password` from the POST body.
3. `Return_approval::verify_approver(string $username, string $password): ?int`
   - trims the username; empty username or password fails immediately,
   - uses a new `Employee::verify_credentials()` (returns the person_id; does not touch
     the session; `check_password()` is refactored to reuse it), which only matches
     non-deleted employees,
   - confirms the approver holds `sales_returns`,
   - returns the approver's `person_id`, or `null` on any failure.
4. On `null`: do not save. Re-render the register with error
   `Sales.return_approval_required` (no credentials supplied) or
   `Sales.return_approval_invalid` (wrong password / approver lacks permission).
   The cart is left intact.
5. On success: pass the approver id into every `save_value()` call in `postComplete`.

Because approval and completion happen in one request, the cart cannot change between
approval and save. It also covers any client-side bypass: without valid credentials in the request
the server refuses.

The approver's password is never stored or logged.

### 5. Register UI

`app/Views/sales/register.php`:

- Controller passes `$return_approval_needed` (result of `requires_approval()`
  for the current cart and logged-in employee).
- When true, the **Complete** click handlers (`#finish_sale_button`,
  `#finish_invoice_quote_button`) open a Bootstrap 3 modal instead of submitting:
  title "Supervisor approval required", username + password fields, Approve / Cancel.
- Approve copies the values into hidden `approver_username` / `approver_password` inputs
  in `#buttons_form` and submits it. Password field uses `autocomplete="off"`.
- If the server returns `return_approval_invalid`, the register shows the error and
  the modal re-opens on next Complete.

### 6. Reporting

Detailed Sales report gets an **"Approved by"** summary column:

- `Sale::create_temp_table()` joins `ospos_people` on `sales.return_approved_by` and
  selects `approver_name` (empty when null).
- `Reports\Detailed_sales::getDataColumns()` adds `['approver_name' => lang('Reports.approved_by')]`
  after `employee_name`.
- `Reports::detailed_sales()` and `getGet_detailed_sales_row()` include `approver_name`
  in each summary row, handled the same way as `employee_name`.

### 7. Language strings (`en`)

- `Sales.return_approval_title` — "Supervisor approval required"
- `Sales.return_approval_required` — "This sale contains returned items and needs supervisor approval."
- `Sales.return_approval_invalid` — "Approval failed: wrong credentials, or this user may not approve returns."
- `Sales.approve` — "Approve"
- `Reports.approved_by` — "Approved by"
- Permission label for `sales_returns` — "Approve Returns"

## Testing

PHPUnit (needs the test MySQL database):

- `Return_approval::cart_has_returns()` / `requires_approval()`:
  - cart without negative lines → false;
  - negative line + employee without permission → true;
  - negative line + employee with permission → false;
  - zero-quantity line only → false.
- `Return_approval::verify_approver()`:
  - valid supervisor with permission → their id;
  - wrong password → null;
  - valid credentials but no `sales_returns` → null;
  - deleted employee → null.
- Migration runs up and down cleanly (checked manually; no DB-backed PHPUnit tests,
  because the `tests` DB group points at the live `ospos` database).

Manual check on the register:

1. Cashier without permission: return via Return mode → modal → wrong password rejected,
   cart intact → correct supervisor → sale completes, stock increases, cashier still logged in.
2. Exchange in Sale mode (one negative, one positive line) → modal appears.
3. Admin (has permission) completes a return → no modal, approver empty.
4. Submitting the Complete form from the browser console with a return in the cart →
   refused by the server (not a bypass).
5. Detailed Sales report shows "Approved by" for the approved sale.
