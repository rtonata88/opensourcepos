# Supervisor Approval for Returns Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cashiers without the new `sales_returns` permission can only complete a sale containing negative-quantity lines when a supervisor enters valid credentials at checkout, without the cashier being logged out; the approver is stored on the sale and shown in the Detailed Sales report.

**Architecture:** A small, unit-tested library `App\Libraries\Return_approval` decides whether a cart needs approval and verifies an approver's credentials (via a new `Employee::verify_credentials()` that never touches the session). `Sales::postComplete()` enforces it server-side in the same request that saves the sale; the register shows a Bootstrap 3 modal that posts the credentials with the Complete form. A migration adds the permission and a nullable `ospos_sales.return_approved_by` column, which the Detailed Sales report displays.

**Tech Stack:** PHP 8.1+, CodeIgniter 4.6, MySQL/MariaDB, PHPUnit 11, Bootstrap 3 + jQuery.

**Spec:** `docs/superpowers/specs/2026-10-11-supervisor-return-approval-design.md`

## Global Constraints

- Permission id is exactly `sales_returns`, module `sales`; granted to `person_id` 1 by the migration.
- Column is exactly `ospos_sales.return_approved_by`, `INT(10) NULL DEFAULT NULL`, FK to `ospos_employees.person_id`.
- POST field names are exactly `approver_username` and `approver_password`.
- The approver's password is never stored, logged, or echoed back to the page.
- Approval is checked only in `Sales::postComplete()`; adding items, editing lines, suspending and cancelling are unchanged.
- **Do not write database-backed PHPUnit tests.** The `tests` DB group in `app/Config/Database.php` points at the live `ospos` database; a DB test with refresh would wipe it. Unit tests use mocks only.
- Run tests with `vendor/bin/phpunit --no-coverage` (no coverage driver is installed; coverage warnings otherwise fail the run).
- Follow CodeIgniter 4 coding standard; run `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.no-header.php` on touched PHP files before each commit.
- `docs/` is git-ignored in this repo; plan/spec files must be added with `git add -f`.
- English (`app/Language/en/`) strings only. The installation's `language_code` is `en`.
- End every commit message with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Fractional or string quantities from the session** (e.g. `-0.5`, `'-1.000'`): must count as a return. → Task 1 tests `testFractionalNegativeIsAReturn`, `testStringQuantityIsAReturn`.
2. **Supervisor typing their username with stray spaces** (`" boss "`): should still be accepted. → Task 1 test `testApproverUsernameIsTrimmed`.
3. **Cashier enters their own credentials** in the popup: must be rejected because they lack `sales_returns`. → Task 1 test `testApproverWithoutPermissionIsRejected`.
4. **Failed approval must not lose the cart or payments**: controller returns before any save and re-renders the register. → Task 3 manual check step 2.
5. **Credentials posted for a cart with no returns** (e.g. stale hidden fields): must be ignored and `return_approved_by` stays NULL. → Task 3 manual check step 5.

## File Structure

| File | Responsibility |
|---|---|
| `app/Libraries/Return_approval.php` (new) | Decide if a cart needs approval; verify an approver |
| `tests/Libraries/Return_approvalTest.php` (new) | Unit tests for the above, mocking `Employee` |
| `phpunit.xml.dist` | Fix broken bootstrap path so tests can run |
| `app/Models/Employee.php` | New `verify_credentials()`; `check_password()` reuses it |
| `app/Database/Migrations/20261011000000_SalesReturnsApproval.php` (new) | Migration up/down |
| `app/Database/Migrations/sqlscripts/3.4.3_sales_returns_approval.sql` (new) | Permission, grant, column, FK |
| `app/Models/Sale.php` | `allowedFields`, `save_value()` param, report temp-table join |
| `app/Controllers/Sales.php` | Enforcement in `postComplete()`, flag for the view in `_reload()` |
| `app/Views/sales/register.php` | Approval modal + hidden fields + Complete handlers |
| `app/Models/Reports/Detailed_sales.php` | "Approved by" column + selects |
| `app/Controllers/Reports.php` | `approver_name` in summary rows |
| `app/Language/en/Sales.php`, `app/Language/en/Reports.php` | Strings |

---

### Task 1: Return_approval library and credential check

**Files:**
- Modify: `phpunit.xml.dist:5`
- Create: `app/Libraries/Return_approval.php`
- Modify: `app/Models/Employee.php:497-513` (`check_password`)
- Test: `tests/Libraries/Return_approvalTest.php`

**Interfaces:**
- Consumes: `Employee::has_grant(?string $permission_id, ?int $person_id): bool` (existing).
- Produces:
  - `Employee::verify_credentials(string $username, string $password): ?int` — person_id of a non-deleted employee whose password matches, else null. Never touches the session.
  - `Return_approval::__construct(?Employee $employee = null)`
  - `Return_approval::PERMISSION` = `'sales_returns'`
  - `Return_approval::cart_has_returns(array $cart): bool` (static)
  - `Return_approval::requires_approval(array $cart, int $employee_id): bool`
  - `Return_approval::verify_approver(string $username, string $password): ?int`

- [ ] **Step 1: Fix the PHPUnit bootstrap path**

`phpunit.xml.dist` line 5 points at `system/Test/bootstrap.php`, which does not exist (CI4 lives in `vendor/`). Change it to:

```xml
    bootstrap="vendor/codeigniter4/framework/system/Test/bootstrap.php"
```

Run: `vendor/bin/phpunit --no-coverage`
Expected: `OK (1 test, 2 assertions)`

- [ ] **Step 2: Write the failing tests**

Create `tests/Libraries/Return_approvalTest.php`:

```php
<?php

use App\Libraries\Return_approval;
use App\Models\Employee;
use PHPUnit\Framework\TestCase;

class Return_approvalTest extends TestCase
{
    /**
     * Builds a cart shaped like Sale_lib::get_cart() with the given line quantities.
     */
    private function cart(array $quantities): array
    {
        $cart = [];
        foreach ($quantities as $index => $quantity) {
            $line = $index + 1;
            $cart[$line] = ['item_id' => 1, 'line' => $line, 'quantity' => $quantity];
        }

        return $cart;
    }

    public function testEmptyCartHasNoReturns(): void
    {
        $this->assertFalse(Return_approval::cart_has_returns([]));
    }

    public function testPositiveQuantitiesAreNotReturns(): void
    {
        $this->assertFalse(Return_approval::cart_has_returns($this->cart([1, 2.5])));
    }

    public function testZeroQuantityIsNotAReturn(): void
    {
        $this->assertFalse(Return_approval::cart_has_returns($this->cart([0])));
    }

    public function testNegativeQuantityIsAReturn(): void
    {
        $this->assertTrue(Return_approval::cart_has_returns($this->cart([1, -1])));
    }

    public function testFractionalNegativeIsAReturn(): void
    {
        $this->assertTrue(Return_approval::cart_has_returns($this->cart([-0.5])));
    }

    public function testStringQuantityIsAReturn(): void
    {
        $this->assertTrue(Return_approval::cart_has_returns($this->cart(['-1.000'])));
    }

    public function testNoApprovalNeededWithoutReturns(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->expects($this->never())->method('has_grant');

        $approval = new Return_approval($employee);

        $this->assertFalse($approval->requires_approval($this->cart([2]), 7));
    }

    public function testApprovalNeededForCashierWithoutPermission(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->expects($this->once())
            ->method('has_grant')
            ->with('sales_returns', 7)
            ->willReturn(false);

        $approval = new Return_approval($employee);

        $this->assertTrue($approval->requires_approval($this->cart([-1]), 7));
    }

    public function testNoApprovalNeededForCashierWithPermission(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->method('has_grant')->with('sales_returns', 7)->willReturn(true);

        $approval = new Return_approval($employee);

        $this->assertFalse($approval->requires_approval($this->cart([-1]), 7));
    }

    public function testValidSupervisorIsAccepted(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->method('verify_credentials')->with('boss', 'secret')->willReturn(3);
        $employee->method('has_grant')->with('sales_returns', 3)->willReturn(true);

        $approval = new Return_approval($employee);

        $this->assertSame(3, $approval->verify_approver('boss', 'secret'));
    }

    public function testApproverUsernameIsTrimmed(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->expects($this->once())
            ->method('verify_credentials')
            ->with('boss', 'secret')
            ->willReturn(3);
        $employee->method('has_grant')->willReturn(true);

        $approval = new Return_approval($employee);

        $this->assertSame(3, $approval->verify_approver('  boss ', 'secret'));
    }

    public function testWrongPasswordIsRejected(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->method('verify_credentials')->willReturn(null);
        $employee->expects($this->never())->method('has_grant');

        $approval = new Return_approval($employee);

        $this->assertNull($approval->verify_approver('boss', 'wrong'));
    }

    public function testApproverWithoutPermissionIsRejected(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->method('verify_credentials')->willReturn(5);
        $employee->method('has_grant')->with('sales_returns', 5)->willReturn(false);

        $approval = new Return_approval($employee);

        $this->assertNull($approval->verify_approver('cashier', 'theirpassword'));
    }

    public function testEmptyUsernameIsRejectedWithoutLookup(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->expects($this->never())->method('verify_credentials');

        $approval = new Return_approval($employee);

        $this->assertNull($approval->verify_approver('   ', 'secret'));
    }

    public function testEmptyPasswordIsRejectedWithoutLookup(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->expects($this->never())->method('verify_credentials');

        $approval = new Return_approval($employee);

        $this->assertNull($approval->verify_approver('boss', ''));
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/Libraries/Return_approvalTest.php`
Expected: FAIL — `Class "App\Libraries\Return_approval" not found`.

- [ ] **Step 4: Add `Employee::verify_credentials()` and make `check_password()` use it**

In `app/Models/Employee.php`, replace the existing `check_password()` method (docblock "Attempts to log in employee and set session…") with:

```php
    /**
     * Checks an employee's username and password without touching the session.
     *
     * @return int|null person_id of the matching, non-deleted employee, or null
     */
    public function verify_credentials(string $username, string $password): ?int
    {
        $builder = $this->db->table('employees');
        $query = $builder->getWhere(['username' => $username, 'deleted' => 0], 1);

        if ($query->getNumRows() === 1) {
            $row = $query->getRow();

            if (password_verify($password, $row->password)) {
                return (int) $row->person_id;
            }
        }

        return null;
    }

    /**
     * Checks an employee's username and password. Does not set the session.
     */
    public function check_password(string $username, string $password): bool
    {
        return $this->verify_credentials($username, $password) !== null;
    }
```

- [ ] **Step 5: Create the library**

Create `app/Libraries/Return_approval.php`:

```php
<?php

namespace App\Libraries;

use App\Models\Employee;

/**
 * Decides when a sale containing returned items needs supervisor approval,
 * and verifies the approving supervisor.
 */
class Return_approval
{
    public const PERMISSION = 'sales_returns';

    private Employee $employee;

    public function __construct(?Employee $employee = null)
    {
        $this->employee = $employee ?? model(Employee::class);
    }

    /**
     * True when any cart line has a negative quantity.
     */
    public static function cart_has_returns(array $cart): bool
    {
        foreach ($cart as $item) {
            if ((float) $item['quantity'] < 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the cart contains returns and the employee may not approve them.
     */
    public function requires_approval(array $cart, int $employee_id): bool
    {
        return self::cart_has_returns($cart)
            && !$this->employee->has_grant(self::PERMISSION, $employee_id);
    }

    /**
     * Returns the approver's person_id when the credentials are valid and the
     * employee holds the returns permission, otherwise null.
     */
    public function verify_approver(string $username, string $password): ?int
    {
        $username = trim($username);

        if ($username === '' || $password === '') {
            return null;
        }

        $approver_id = $this->employee->verify_credentials($username, $password);

        if ($approver_id === null || !$this->employee->has_grant(self::PERMISSION, $approver_id)) {
            return null;
        }

        return $approver_id;
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit --no-coverage`
Expected: `OK (16 tests, …)` — 15 new tests plus the existing `UrlHelperTest`.

- [ ] **Step 7: Check login still works**

`check_password()` is used by the change-password flow. Run:
`grep -rn "check_password(" app/` — confirm callers only rely on the boolean result.

- [ ] **Step 8: Coding standard and commit**

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.no-header.php app/Libraries/Return_approval.php app/Models/Employee.php tests/Libraries/Return_approvalTest.php
git add phpunit.xml.dist app/Libraries/Return_approval.php app/Models/Employee.php tests/Libraries/Return_approvalTest.php
git commit -m "Add Return_approval library for supervisor approval of returns

Also fix the PHPUnit bootstrap path so the test suite runs.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Migration — permission, grant, column; Sale model accepts approver

**Files:**
- Create: `app/Database/Migrations/sqlscripts/3.4.3_sales_returns_approval.sql`
- Create: `app/Database/Migrations/20261011000000_SalesReturnsApproval.php`
- Modify: `app/Models/Sale.php:21-32` (`$allowedFields`), `app/Models/Sale.php:503-546` (`save_value`)
- Modify: `app/Language/en/Sales.php` (permission label)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces:
  - DB: permission `sales_returns`; column `ospos_sales.return_approved_by`.
  - `Sale::save_value(..., ?array &$sales_taxes, ?int $return_approved_by = null): int` — new trailing optional parameter.
  - Language key `Sales.returns` (permission label, read by `app/Views/employees/form.php:136` as `Sales.returns`).

- [ ] **Step 1: Write the SQL script**

Create `app/Database/Migrations/sqlscripts/3.4.3_sales_returns_approval.sql`:

```sql
INSERT INTO `ospos_permissions` (`permission_id`, `module_id`) VALUES
('sales_returns', 'sales');

INSERT INTO `ospos_grants` (`permission_id`, `person_id`, `menu_group`) VALUES
('sales_returns', 1, '--');

ALTER TABLE `ospos_sales`
  ADD COLUMN `return_approved_by` INT(10) NULL DEFAULT NULL;

ALTER TABLE `ospos_sales`
  ADD CONSTRAINT `ospos_sales_ibfk_return_approved_by`
  FOREIGN KEY (`return_approved_by`) REFERENCES `ospos_employees` (`person_id`);
```

- [ ] **Step 2: Write the migration**

Create `app/Database/Migrations/20261011000000_SalesReturnsApproval.php`:

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_SalesReturnsApproval extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');
        executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/3.4.3_sales_returns_approval.sql');
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        $sales = $this->db->prefixTable('sales');

        $this->db->query("ALTER TABLE `$sales` DROP FOREIGN KEY `ospos_sales_ibfk_return_approved_by`");
        $this->db->query("ALTER TABLE `$sales` DROP COLUMN `return_approved_by`");
        $this->db->table('grants')->where('permission_id', 'sales_returns')->delete();
        $this->db->table('permissions')->where('permission_id', 'sales_returns')->delete();
    }
}
```

- [ ] **Step 3: Run the migration up and verify**

```bash
php spark migrate
mysql -uadmin -ppointofsale ospos -e "SELECT * FROM ospos_grants WHERE permission_id='sales_returns'; SHOW COLUMNS FROM ospos_sales LIKE 'return_approved_by';"
```
Expected: one grant row for person_id 1; one column row `return_approved_by | int(10) | YES | MUL | NULL`.

- [ ] **Step 4: Verify the migration rolls back, then re-apply**

```bash
php spark migrate:rollback -b 1
mysql -uadmin -ppointofsale ospos -e "SELECT COUNT(*) FROM ospos_permissions WHERE permission_id='sales_returns'; SHOW COLUMNS FROM ospos_sales LIKE 'return_approved_by';"
php spark migrate
```
Expected after rollback: count `0`, no column row. Confirm `php spark migrate:status` shows only this migration in the rolled-back batch before running rollback; if the batch contains other migrations, skip the rollback check and note it.

- [ ] **Step 5: Accept the approver in the Sale model**

In `app/Models/Sale.php` add `'return_approved_by'` to `$allowedFields` after `'sale_type'`:

```php
        'sale_type',
        'return_approved_by'
    ];
```

Change the end of the `save_value()` signature from:

```php
        ?int $dinner_table_id,
        ?array &$sales_taxes
    ): int {
```
to:
```php
        ?int $dinner_table_id,
        ?array &$sales_taxes,
        ?int $return_approved_by = null
    ): int {
```

And add the field to `$sales_data`:

```php
            'dinner_table_id'    => $dinner_table_id,
            'sale_type'          => $sale_type,
            'return_approved_by' => $return_approved_by
        ];
```
(Re-align the `=>` of the whole array to match php-cs-fixer output.)

- [ ] **Step 6: Permission label**

In `app/Language/en/Sales.php`, add after the `"return"` entry:

```php
    "returns"                          => "Approve Returns",
```

Open Employees → edit admin → Permissions. Expected: under Sales, a ticked **Approve Returns** box.

- [ ] **Step 7: Coding standard and commit**

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.no-header.php app/Models/Sale.php app/Database/Migrations/20261011000000_SalesReturnsApproval.php app/Language/en/Sales.php
vendor/bin/phpunit --no-coverage
git add app/Database/Migrations/sqlscripts/3.4.3_sales_returns_approval.sql app/Database/Migrations/20261011000000_SalesReturnsApproval.php app/Models/Sale.php app/Language/en/Sales.php
git commit -m "Add sales_returns permission and return_approved_by column

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Enforce approval in checkout and show the popup

Server enforcement and the popup ship together: enforcement alone would make returns impossible for cashiers, and the popup alone protects nothing.

**Files:**
- Modify: `app/Controllers/Sales.php` (imports, properties, constructor ~line 50, `postComplete()` lines 642-860, `_reload()` ~line 1124)
- Modify: `app/Views/sales/register.php` (docblock vars ~line 4-36, `#buttons_form` ~line 493, Complete handlers lines 730-738)
- Modify: `app/Language/en/Sales.php`

**Interfaces:**
- Consumes: `Return_approval::requires_approval()`, `Return_approval::verify_approver()` (Task 1); `Sale::save_value(..., ?int $return_approved_by)` (Task 2).
- Produces: view variable `$return_approval_needed` (bool); POST fields `approver_username`, `approver_password`; language keys `Sales.return_approval_title`, `Sales.return_approval_required`, `Sales.return_approval_invalid`, `Sales.approve`, `Sales.approver_username`, `Sales.approver_password`.

- [ ] **Step 1: Language strings**

Add to `app/Language/en/Sales.php` (keep the file's alignment):

```php
    "approve"                          => "Approve",
    "approver_password"                => "Supervisor password",
    "approver_username"                => "Supervisor username",
    "return_approval_invalid"          => "Approval failed: wrong username or password, or this user may not approve returns.",
    "return_approval_required"         => "This sale contains returned items and needs supervisor approval.",
    "return_approval_title"            => "Supervisor approval required",
```

- [ ] **Step 2: Wire the library into the controller**

In `app/Controllers/Sales.php`:
- add `use App\Libraries\Return_approval;` with the other `App\Libraries` imports;
- add property `private Return_approval $return_approval;` next to the other library properties;
- in the constructor, after `$this->employee = model(Employee::class);`, add:

```php
        $this->return_approval = new Return_approval($this->employee);
```

- [ ] **Step 3: Enforce in `postComplete()`**

Directly after these existing lines in `postComplete()`:

```php
        $employee_id = $this->employee->get_logged_in_employee_info()->person_id;
        $employee_info = $this->employee->get_info($employee_id);
```

insert:

```php
        $return_approved_by = null;

        if ($this->return_approval->requires_approval($data['cart'], $employee_id)) {
            $approver_username = (string) $this->request->getPost('approver_username');
            $approver_password = (string) $this->request->getPost('approver_password');
            $return_approved_by = $this->return_approval->verify_approver($approver_username, $approver_password);

            if ($return_approved_by === null) {
                $error = trim($approver_username) === '' ? 'Sales.return_approval_required' : 'Sales.return_approval_invalid';
                $this->_reload(['error' => lang($error)]);

                return;
            }
        }
```

Then append `, $return_approved_by` to all four `save_value(...)` calls in `postComplete()` (currently lines ~758, 795, 824, 843), so each ends:

```php
$data['payments'], $data['dinner_table'], $tax_details, $return_approved_by);
```

Run: `grep -cF '$tax_details, $return_approved_by);' app/Controllers/Sales.php`
Expected: `4`

- [ ] **Step 4: Tell the view whether approval is needed**

In `_reload()`, directly after `$data['cart'] = $this->sale_lib->get_cart();` add:

```php
        $data['return_approval_needed'] = $this->return_approval->requires_approval(
            $data['cart'],
            $this->employee->get_logged_in_employee_info()->person_id
        );
```

- [ ] **Step 5: Register view — docblock, hidden fields, modal**

In `app/Views/sales/register.php`:

Add to the docblock at the top: ` * @var bool $return_approval_needed`

Directly after the line `<?= form_open("$controller_name/cancel", ['id' => 'buttons_form']) ?>` add:

```php
            <?= form_hidden('approver_username', '') ?>
            <?= form_hidden('approver_password', '') ?>
```

Just before the first `<script` tag that contains `$('#finish_sale_button').click(` add the modal:

```php
<div class="modal fade" id="return_approval_modal" tabindex="-1" role="dialog" aria-labelledby="return_approval_label">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="<?= lang('Common.close') ?>"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="return_approval_label"><?= lang('Sales.return_approval_title') ?></h4>
            </div>
            <div class="modal-body">
                <p><?= lang('Sales.return_approval_required') ?></p>
                <div class="form-group">
                    <label for="return_approver_username"><?= lang('Sales.approver_username') ?></label>
                    <input type="text" class="form-control input-sm" id="return_approver_username" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="return_approver_password"><?= lang('Sales.approver_password') ?></label>
                    <input type="password" class="form-control input-sm" id="return_approver_password" autocomplete="new-password">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default btn-sm" data-dismiss="modal"><?= lang('Common.close') ?></button>
                <button type="button" class="btn btn-success btn-sm" id="return_approval_submit"><?= lang('Sales.approve') ?></button>
            </div>
        </div>
    </div>
</div>
```

`Common.close` ("Close") exists in `app/Language/en/Common.php`; there is no `Common.cancel`, so the dismiss button uses `Common.close`.

- [ ] **Step 6: Register view — route Complete through the modal**

Replace the two existing handlers (lines ~730-738):

```js
        $('#finish_sale_button').click(function() {
            $('#buttons_form').attr('action', "<?= "$controller_name/complete" ?>");
            $('#buttons_form').submit();
        });

        $('#finish_invoice_quote_button').click(function() {
            $('#buttons_form').attr('action', "<?= "$controller_name/complete" ?>");
            $('#buttons_form').submit();
        });
```

with:

```js
        var return_approval_needed = <?= json_encode((bool) $return_approval_needed) ?>;

        function submit_complete() {
            $('#buttons_form').attr('action', "<?= "$controller_name/complete" ?>");
            $('#buttons_form').submit();
        }

        function complete_or_request_approval() {
            if (return_approval_needed) {
                $('#return_approval_modal').modal('show');
            } else {
                submit_complete();
            }
        }

        $('#finish_sale_button').click(complete_or_request_approval);
        $('#finish_invoice_quote_button').click(complete_or_request_approval);

        $('#return_approval_modal').on('shown.bs.modal', function() {
            $('#return_approver_username').focus();
        });

        $('#return_approval_submit').click(function() {
            $('#buttons_form input[name="approver_username"]').val($('#return_approver_username').val());
            $('#buttons_form input[name="approver_password"]').val($('#return_approver_password').val());
            submit_complete();
        });

        $('#return_approver_password').keypress(function(event) {
            if (event.which == 13) {
                $('#return_approval_submit').click();
            }
        });
```

- [ ] **Step 7: Run unit tests and coding standard**

```bash
vendor/bin/phpunit --no-coverage
vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.no-header.php app/Controllers/Sales.php app/Language/en/Sales.php
```
Expected: tests OK; no style diff.

- [ ] **Step 8: Manual check on the register**

Setup: create a test employee `cashier1` with the Sales module but **without** Approve Returns. Admin (person_id 1) has Approve Returns. Note Milk's stock first.

1. Log in as `cashier1`. Switch to Return mode, scan Milk, add a cash payment, press **Complete**. Expected: the modal opens; nothing is submitted.
2. Enter `admin` with a wrong password → Approve. Expected: register reloads with "Approval failed…" error; cart and payment still present; Milk stock unchanged; still logged in as `cashier1`.
3. Press Complete, enter `cashier1`'s own credentials. Expected: "Approval failed…" (no permission).
4. Press Complete, enter admin's correct credentials. Expected: receipt shown; Milk stock +1; header still shows `cashier1`; `SELECT employee_id, return_approved_by FROM ospos_sales ORDER BY sale_id DESC LIMIT 1;` → cashier1's id, `1`.
5. As `cashier1`, Sale mode, normal sale of one item, Complete. Expected: no modal; `return_approved_by` NULL. Also in browser devtools set the hidden `approver_username`/`approver_password` fields to admin's credentials before completing a no-return sale → `return_approved_by` still NULL.
6. As `cashier1`, Sale mode: Milk qty 1 and another item edited to qty -1 (exchange). Expected: modal appears on Complete.
7. Log in as admin, complete a return. Expected: no modal; `return_approved_by` NULL.
8. Bypass check: as `cashier1` with a return in the cart, run in the browser console `$('#buttons_form').attr('action','sales/complete').submit()`. Expected: "This sale contains returned items and needs supervisor approval."; no sale saved.

- [ ] **Step 9: Commit**

```bash
git add app/Controllers/Sales.php app/Views/sales/register.php app/Language/en/Sales.php
git commit -m "Require supervisor approval to complete sales with returns

Cashiers without the sales_returns permission must have a supervisor
enter their credentials at checkout. Checked server-side in the same
request that saves the sale; the cashier stays logged in.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: "Approved by" column in the Detailed Sales report

**Files:**
- Modify: `app/Models/Sale.php` (`create_temp_table()` SQL, ~lines 1110 and 1148)
- Modify: `app/Models/Reports/Detailed_sales.php` (`getDataColumns()` ~line 37, selects ~lines 77 and 112)
- Modify: `app/Controllers/Reports.php` (`getGet_detailed_sales_row()` ~line 1640, `detailed_sales()` ~line 1811)
- Modify: `app/Language/en/Reports.php`

**Interfaces:**
- Consumes: column `ospos_sales.return_approved_by` (Task 2).
- Produces: temp-table column `approver_name`; report field key `approver_name`; language key `Reports.approved_by`.

- [ ] **Step 1: Language string**

Add to `app/Language/en/Reports.php` (alphabetical position, keep alignment):

```php
    "approved_by"                             => "Approved By",
```

- [ ] **Step 2: Add the approver to the temp table**

In `Sale::create_temp_table()`, after:

```php
                    MAX(CONCAT(employee.first_name, " ", employee.last_name)) AS employee_name,
```
add:
```php
                    MAX(CONCAT(approver.first_name, " ", approver.last_name)) AS approver_name,
```

and after the join:

```php
                LEFT OUTER JOIN ' . $this->db->prefixTable('people') . ' AS employee
                    ON sales.employee_id = employee.person_id
```
add:
```php
                LEFT OUTER JOIN ' . $this->db->prefixTable('people') . ' AS approver
                    ON sales.return_approved_by = approver.person_id
```

- [ ] **Step 3: Select it in the report model**

In `app/Models/Reports/Detailed_sales.php`, in **both** `getDataBySaleId()` and `getData()`, after `MAX(employee_name) AS employee_name,` add:

```php
            MAX(approver_name) AS approver_name,
```

In `getDataColumns()` summary, after the `employee_name` entry add:

```php
                ['approver_name' => lang('Reports.approved_by')],
```

- [ ] **Step 4: Put it in the rows**

In `app/Controllers/Reports.php`, in `getGet_detailed_sales_row()` after `'employee_name' => $report_data['employee_name'],` add:

```php
            'approver_name' => $report_data['approver_name'] ?? '',
```

and in `detailed_sales()` after `'employee_name' => $row['employee_name'],` add:

```php
                'approver_name' => $row['approver_name'] ?? '',
```

(Same handling as `employee_name`; the report table renders both the same way.)

- [ ] **Step 5: Manual check**

Reports → Detailed Reports → Sales, date range including today, type All.
Expected: an **Approved By** column after Sold By; the sale approved in Task 3 step 4 shows the admin's name; other sales blank. Edit a sale from the report (pencil) and save; the row refreshes and the column still shows correctly. Export/print the report and confirm the column lines up.

- [ ] **Step 6: Coding standard, tests, commit**

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.no-header.php app/Models/Sale.php app/Models/Reports/Detailed_sales.php app/Controllers/Reports.php app/Language/en/Reports.php
vendor/bin/phpunit --no-coverage
git add app/Models/Sale.php app/Models/Reports/Detailed_sales.php app/Controllers/Reports.php app/Language/en/Reports.php
git commit -m "Show return approver in Detailed Sales report

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
