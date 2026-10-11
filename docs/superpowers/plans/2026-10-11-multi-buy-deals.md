# Multi-buy Deals Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each item can carry one optional "N for X" deal that the sales register applies automatically by splitting the item into a deal line and a normal line.

**Architecture:** A pure, unit-tested library `App\Libraries\Deal_pricing` validates deals and rewrites cart lines. `Sale_lib::apply_deals()` loads deal settings (new `ospos_items.deal_quantity` / `deal_price` columns via `Item::get_deals()`), calls the library, recomputes line totals with the existing `get_item_total()`, and saves the cart; the Sales controller calls it before every register render and before saving a sale.

**Tech Stack:** PHP 8.1+, CodeIgniter 4.6, MySQL/MariaDB, bcmath, PHPUnit 11, Bootstrap 3 + jQuery.

**Spec:** `docs/superpowers/specs/2026-10-11-multi-buy-deals-design.md`

## Global Constraints

- Columns exactly `ospos_items.deal_quantity INT(10) NULL DEFAULT NULL` and `ospos_items.deal_price DECIMAL(15,2) NULL DEFAULT NULL`; both NULL = no deal.
- Deal price ÷ deal quantity must be whole cents; deal quantity integer ≥ 2; deal price > 0 and < quantity × unit price.
- Deal lines carry discount 0; the normal line keeps the item's normal discount.
- Deals never apply in Return mode, to item-kit lines, to serialized items, to lines whose price/discount the cashier edited, or to lines whose price is neither the unit price nor the deal unit price.
- Expected totals for Milk 7.00, deal 2 for 13.00: qty 1 → 7.00, 2 → 13.00, 3 → 20.00, 4 → 26.00.
- **No database-backed PHPUnit tests.** The `tests` DB group points at the live `ospos` database. `Deal_pricing` tests are pure.
- Run tests with `vendor/bin/phpunit --no-coverage`.
- CodeIgniter 4 coding standard: `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.no-header.php <files>` must be clean on new files; in existing files fix only lines you changed.
- English strings only (`app/Language/en/`).
- Never run SQL INSERT/UPDATE/DELETE/ALTER/DROP/TRUNCATE by hand against the local `ospos` DB; the only schema change is through `php spark migrate`. Manual checks go through the app's HTTP endpoints.
- `docs/` is git-ignored: add plan/spec files with `git add -f`. Don't commit `build/`.
- Commit messages end with exactly `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **A line with an overridden price** (barcode price token, manual price) must be left alone, not silently re-priced → Task 1 test `testOverriddenPriceLineIsUntouched`.
2. **Scanning another unit after the split** merges into the 6.50 deal line (`add_item` merges into the last matching line) — must re-split correctly → Task 1 test `testExtraUnitMergedIntoDealLineIsResplit`.
3. **Unit price lowered after the deal was set** (deal no longer cheaper) — the deal must stop applying rather than overcharge → Task 1 test `testInactiveDealLeavesLinesAlone`.
4. **Serialized items** must keep one line per serial number → Task 1 test `testSerializedLineIsUntouched`.
5. **Customer discount applied before/after the split** — normal unit keeps the discount; deal units don't → Task 1 tests `testCustomerDiscountMovesToNormalLine` and `testStoredNormalDiscountIsReused`.

## File Structure

| File | Responsibility |
|---|---|
| `app/Libraries/Deal_pricing.php` (new) | Pure deal maths: validation, unit price, quantity split, cart rewrite |
| `tests/Libraries/Deal_pricingTest.php` (new) | Unit tests for the above |
| `app/Database/Migrations/20261012000000_ItemDeals.php` + `sqlscripts/3.4.4_item_deals.sql` (new) | Add the two columns |
| `app/Models/Item.php` | `allowedFields`, new `get_deals()` |
| `app/Controllers/Items.php`, `app/Views/items/form.php`, `app/Language/en/Items.php` | Configure and validate a deal |
| `app/Libraries/Sale_lib.php` | `from_kit` / `deal_excluded` line flags, `apply_deals()` |
| `app/Controllers/Sales.php` | Call `apply_deals()` in `_reload()`, `postComplete()`, `postSuspend()` |

---

### Task 1: Deal_pricing library

**Files:**
- Create: `app/Libraries/Deal_pricing.php`
- Test: `tests/Libraries/Deal_pricingTest.php`

**Interfaces:**
- Consumes: constants `PERCENT` (0) from `app/Config/Constants.php` (loaded by the CI4 test bootstrap).
- Produces (all `public static`):
  - `Deal_pricing::validate_deal(string $quantity, string $price, string $unit_price): ?string` — `''` means blank; returns an `Items.*` language key or null.
  - `Deal_pricing::deal_unit_price(int $deal_quantity, string $deal_price): string` (2 dp)
  - `Deal_pricing::split_quantity(string $quantity, int $deal_quantity): array` → `['deal' => string, 'normal' => string]` (3 dp)
  - `Deal_pricing::apply(array $cart, array $deals): array` — `$deals`: `item_id => ['deal_quantity' => int, 'deal_price' => string, 'unit_price' => string]`. Returns the cart with managed lines rewritten (quantity, price, discount, discount_type, line, plus `deal_normal_discount` / `deal_normal_discount_type` on deal lines). Does not touch `total` / `discounted_total`.
  - Cart line keys read: `item_id`, `line`, `quantity`, `price`, `discount`, `discount_type`, `is_serialized`, and the optional flags `from_kit`, `deal_excluded`, `deal_normal_discount`, `deal_normal_discount_type`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Libraries/Deal_pricingTest.php`:

```php
<?php

use App\Libraries\Deal_pricing;
use PHPUnit\Framework\TestCase;

class Deal_pricingTest extends TestCase
{
    private const MILK = 3;
    private const BREAD = 9;

    private function deals(string $unit_price = '7.00'): array
    {
        return [self::MILK => ['deal_quantity' => 2, 'deal_price' => '13.00', 'unit_price' => $unit_price]];
    }

    private function line(int $key, int $item_id, string $quantity, string $price, array $extra = []): array
    {
        return [$key => array_merge([
            'item_id'       => $item_id,
            'line'          => $key,
            'quantity'      => $quantity,
            'price'         => $price,
            'discount'      => '0',
            'discount_type' => PERCENT,
            'is_serialized' => 0,
            'description'   => '',
            'serialnumber'  => '',
        ], $extra)];
    }

    /**
     * Sum of quantity × price, ignoring discounts (all lines in these tests are undiscounted unless stated).
     */
    private function total(array $cart): float
    {
        $total = 0.0;
        foreach ($cart as $line) {
            $total += (float) $line['quantity'] * (float) $line['price'];
        }

        return round($total, 2);
    }

    // validate_deal

    public function testBlankDealIsValid(): void
    {
        $this->assertNull(Deal_pricing::validate_deal('', '', '7.00'));
    }

    public function testHalfFilledDealIsRejected(): void
    {
        $this->assertSame('Items.deal_both_required', Deal_pricing::validate_deal('2', '', '7.00'));
        $this->assertSame('Items.deal_both_required', Deal_pricing::validate_deal('', '13.00', '7.00'));
    }

    public function testDealQuantityMustBeWholeNumberOfAtLeastTwo(): void
    {
        $this->assertSame('Items.deal_quantity_invalid', Deal_pricing::validate_deal('1', '5.00', '7.00'));
        $this->assertSame('Items.deal_quantity_invalid', Deal_pricing::validate_deal('1.5', '5.00', '7.00'));
        $this->assertSame('Items.deal_quantity_invalid', Deal_pricing::validate_deal('abc', '5.00', '7.00'));
    }

    public function testDealPriceMustBePositiveAndCheaper(): void
    {
        $this->assertSame('Items.deal_price_invalid', Deal_pricing::validate_deal('2', '0', '7.00'));
        $this->assertSame('Items.deal_price_invalid', Deal_pricing::validate_deal('2', '14.00', '7.00'));
        $this->assertSame('Items.deal_price_invalid', Deal_pricing::validate_deal('2', 'abc', '7.00'));
        $this->assertSame('Items.deal_price_invalid', Deal_pricing::validate_deal('2', '13.00', 'x'));
    }

    public function testDealPriceMustSplitIntoWholeCents(): void
    {
        $this->assertSame('Items.deal_price_cents', Deal_pricing::validate_deal('3', '10.00', '4.00'));
    }

    public function testValidDealIsAccepted(): void
    {
        $this->assertNull(Deal_pricing::validate_deal('2', '13.00', '7.00'));
        $this->assertNull(Deal_pricing::validate_deal('4', '25', '7'));
    }

    // deal_unit_price

    public function testDealUnitPrice(): void
    {
        $this->assertSame('6.50', Deal_pricing::deal_unit_price(2, '13.00'));
        $this->assertSame('6.25', Deal_pricing::deal_unit_price(4, '25.00'));
    }

    // split_quantity

    public function testSplitQuantity(): void
    {
        $cases = [
            ['1', 0.0, 1.0],
            ['2', 2.0, 0.0],
            ['3', 2.0, 1.0],
            ['4', 4.0, 0.0],
            ['5', 4.0, 1.0],
            ['2.5', 2.0, 0.5],
            ['3.000', 2.0, 1.0],
            ['0', 0.0, 0.0],
            ['-1', 0.0, -1.0],
        ];

        foreach ($cases as [$quantity, $deal, $normal]) {
            $split = Deal_pricing::split_quantity($quantity, 2);
            $this->assertSame($deal, (float) $split['deal'], "deal part of $quantity");
            $this->assertSame($normal, (float) $split['normal'], "normal part of $quantity");
        }
    }

    // apply

    public function testMilkTotalsForOneToFour(): void
    {
        foreach (['1' => 7.0, '2' => 13.0, '3' => 20.0, '4' => 26.0] as $quantity => $expected) {
            $cart = Deal_pricing::apply($this->line(1, self::MILK, (string) $quantity, '7.00'), $this->deals());
            $this->assertSame($expected, $this->total($cart), "total for $quantity milk");
        }
    }

    public function testThreeMilkBecomesDealLineAndNormalLine(): void
    {
        $cart = Deal_pricing::apply($this->line(1, self::MILK, '3', '7.00'), $this->deals());

        $this->assertSame([1, 2], array_keys($cart));
        $this->assertSame(2.0, (float) $cart[1]['quantity']);
        $this->assertSame('6.50', $cart[1]['price']);
        $this->assertSame(0.0, (float) $cart[1]['discount']);
        $this->assertSame(1.0, (float) $cart[2]['quantity']);
        $this->assertSame('7.00', $cart[2]['price']);
        $this->assertSame(2, $cart[2]['line']);
    }

    public function testSeparateScansAreRegrouped(): void
    {
        $cart = $this->line(1, self::MILK, '1', '7.00') + $this->line(2, self::MILK, '2', '7.00');

        $cart = Deal_pricing::apply($cart, $this->deals());

        $this->assertSame([1, 2], array_keys($cart));
        $this->assertSame(2.0, (float) $cart[1]['quantity']);
        $this->assertSame(1.0, (float) $cart[2]['quantity']);
        $this->assertSame(20.0, $this->total($cart));
    }

    public function testExtraUnitMergedIntoDealLineIsResplit(): void
    {
        // add_item merged a third scan into the existing 6.50 deal line
        $cart = Deal_pricing::apply($this->line(1, self::MILK, '3', '6.50'), $this->deals());

        $this->assertSame(20.0, $this->total($cart));
        $this->assertSame('6.50', $cart[1]['price']);
        $this->assertSame('7.00', $cart[2]['price']);
    }

    public function testEvenQuantityLeavesSingleDealLine(): void
    {
        $cart = $this->line(1, self::MILK, '2', '6.50') + $this->line(2, self::MILK, '2', '7.00');

        $cart = Deal_pricing::apply($cart, $this->deals());

        $this->assertSame([1], array_keys($cart));
        $this->assertSame(4.0, (float) $cart[1]['quantity']);
        $this->assertSame(26.0, $this->total($cart));
    }

    public function testCustomerDiscountMovesToNormalLine(): void
    {
        $cart = $this->line(1, self::MILK, '3', '7.00', ['discount' => '5', 'discount_type' => PERCENT]);

        $cart = Deal_pricing::apply($cart, $this->deals());

        $this->assertSame(0.0, (float) $cart[1]['discount']);
        $this->assertSame(5.0, (float) $cart[2]['discount']);
        $this->assertSame(PERCENT, $cart[2]['discount_type']);
        $this->assertSame(5.0, (float) $cart[1]['deal_normal_discount']);
    }

    public function testStoredNormalDiscountIsReused(): void
    {
        $cart = $this->line(1, self::MILK, '3', '6.50', ['deal_normal_discount' => '5', 'deal_normal_discount_type' => PERCENT]);

        $cart = Deal_pricing::apply($cart, $this->deals());

        $this->assertSame(5.0, (float) $cart[2]['discount']);
    }

    public function testExcludedLineIsUntouched(): void
    {
        $cart = $this->line(1, self::MILK, '3', '7.00', ['deal_excluded' => true]);

        $this->assertSame($cart, Deal_pricing::apply($cart, $this->deals()));
    }

    public function testKitLineIsUntouched(): void
    {
        $cart = $this->line(1, self::MILK, '3', '7.00', ['from_kit' => true]);

        $this->assertSame($cart, Deal_pricing::apply($cart, $this->deals()));
    }

    public function testSerializedLineIsUntouched(): void
    {
        $cart = $this->line(1, self::MILK, '1', '7.00', ['is_serialized' => 1]) + $this->line(2, self::MILK, '1', '7.00', ['is_serialized' => 1]);

        $this->assertSame($cart, Deal_pricing::apply($cart, $this->deals()));
    }

    public function testOverriddenPriceLineIsUntouched(): void
    {
        $cart = $this->line(1, self::MILK, '2', '5.00');

        $this->assertSame($cart, Deal_pricing::apply($cart, $this->deals()));
    }

    public function testInactiveDealLeavesLinesAlone(): void
    {
        // unit price lowered to 6.00 after the deal was set: 2 for 13 is no longer cheaper
        $cart = $this->line(1, self::MILK, '3', '6.00');

        $this->assertSame($cart, Deal_pricing::apply($cart, $this->deals('6.00')));
    }

    public function testReturnedQuantityIsUntouched(): void
    {
        $cart = $this->line(1, self::MILK, '-2', '7.00');

        $this->assertSame($cart, Deal_pricing::apply($cart, $this->deals()));
    }

    public function testOtherItemsAndOrderAreKept(): void
    {
        $cart = $this->line(1, self::BREAD, '1', '3.00') + $this->line(2, self::MILK, '3', '7.00') + $this->line(3, self::BREAD, '2', '3.00');

        $cart = Deal_pricing::apply($cart, $this->deals());

        $this->assertSame([1, 2, 4, 3], array_keys($cart));
        $this->assertSame('3.00', $cart[1]['price']);
        $this->assertSame(4, $cart[4]['line']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/Libraries/Deal_pricingTest.php`
Expected: errors — `Class "App\Libraries\Deal_pricing" not found`.

- [ ] **Step 3: Implement the library**

Create `app/Libraries/Deal_pricing.php`:

```php
<?php

namespace App\Libraries;

/**
 * "N for X" multi-buy deals: validation and cart line rewriting.
 * Pure functions: no database or session access.
 */
class Deal_pricing
{
    /**
     * Validates a deal entered on the item form. Empty strings mean "left blank".
     *
     * @return string|null language key of the error, or null when valid
     */
    public static function validate_deal(string $quantity, string $price, string $unit_price): ?string
    {
        if ($quantity === '' && $price === '') {
            return null;
        }

        if ($quantity === '' || $price === '') {
            return 'Items.deal_both_required';
        }

        if (! ctype_digit($quantity) || (int) $quantity < 2) {
            return 'Items.deal_quantity_invalid';
        }

        if (! is_numeric($price) || ! is_numeric($unit_price)
            || bccomp($price, '0', 2) <= 0
            || bccomp($price, bcmul($quantity, $unit_price, 2), 2) >= 0) {
            return 'Items.deal_price_invalid';
        }

        if ((int) round((float) $price * 100) % (int) $quantity !== 0) {
            return 'Items.deal_price_cents';
        }

        return null;
    }

    /**
     * Price of one item when bought as part of the deal.
     */
    public static function deal_unit_price(int $deal_quantity, string $deal_price): string
    {
        return bcdiv($deal_price, (string) $deal_quantity, 2);
    }

    /**
     * Splits a quantity into whole deal groups and the remainder.
     *
     * @return array{deal: string, normal: string}
     */
    public static function split_quantity(string $quantity, int $deal_quantity): array
    {
        if (bccomp($quantity, '0', 3) <= 0) {
            return ['deal' => '0.000', 'normal' => bcadd($quantity, '0', 3)];
        }

        $groups = bcdiv($quantity, (string) $deal_quantity, 0);
        $deal = bcmul($groups, (string) $deal_quantity, 3);

        return ['deal' => $deal, 'normal' => bcsub($quantity, $deal, 3)];
    }

    /**
     * Rewrites the managed lines of every item with a deal into one deal line and one normal line.
     * Line totals are not recomputed here.
     *
     * @param array $deals item_id => ['deal_quantity' => int, 'deal_price' => string, 'unit_price' => string]
     */
    public static function apply(array $cart, array $deals): array
    {
        $managed = [];

        foreach ($cart as $key => $line) {
            $deal = $deals[$line['item_id']] ?? null;

            if ($deal !== null && self::is_managed($line, $deal)) {
                $managed[$line['item_id']][] = $key;
            }
        }

        if ($managed === []) {
            return $cart;
        }

        $next_key = max(array_keys($cart)) + 1;
        $result = [];

        foreach ($cart as $key => $line) {
            $keys = $managed[$line['item_id']] ?? [];

            if (! in_array($key, $keys, true)) {
                $result[$key] = $line;

                continue;
            }

            if ($key !== $keys[0]) {
                continue;
            }

            $deal = $deals[$line['item_id']];
            $quantity = '0';
            $normal_discount = null;
            $normal_discount_type = $line['discount_type'];

            foreach ($keys as $managed_key) {
                $managed_line = $cart[$managed_key];
                $quantity = bcadd($quantity, (string) $managed_line['quantity'], 3);

                if ($normal_discount === null && bccomp((string) $managed_line['discount'], '0', 2) !== 0) {
                    $normal_discount = (string) $managed_line['discount'];
                    $normal_discount_type = $managed_line['discount_type'];
                }
            }

            if ($normal_discount === null) {
                foreach ($keys as $managed_key) {
                    if (isset($cart[$managed_key]['deal_normal_discount'])) {
                        $normal_discount = (string) $cart[$managed_key]['deal_normal_discount'];
                        $normal_discount_type = $cart[$managed_key]['deal_normal_discount_type'];

                        break;
                    }
                }
            }

            $normal_discount ??= '0';
            $split = self::split_quantity($quantity, $deal['deal_quantity']);
            $has_deal = bccomp($split['deal'], '0', 3) > 0;
            $has_normal = bccomp($split['normal'], '0', 3) > 0;
            $normal_key = $has_deal ? ($keys[1] ?? $next_key++) : $key;

            if ($has_deal) {
                $result[$key] = array_merge($line, [
                    'line'                      => $key,
                    'quantity'                  => $split['deal'],
                    'price'                     => self::deal_unit_price($deal['deal_quantity'], $deal['deal_price']),
                    'discount'                  => '0',
                    'deal_normal_discount'      => $normal_discount,
                    'deal_normal_discount_type' => $normal_discount_type,
                ]);
            }

            if ($has_normal) {
                $result[$normal_key] = array_merge($line, [
                    'line'          => $normal_key,
                    'quantity'      => $split['normal'],
                    'price'         => $deal['unit_price'],
                    'discount'      => $normal_discount,
                    'discount_type' => $normal_discount_type,
                ]);
                unset($result[$normal_key]['deal_normal_discount'], $result[$normal_key]['deal_normal_discount_type']);
            }
        }

        return $result;
    }

    /**
     * True when the deal may re-price this cart line.
     */
    private static function is_managed(array $line, array $deal): bool
    {
        if (! empty($line['from_kit']) || ! empty($line['deal_excluded']) || ! empty($line['is_serialized'])) {
            return false;
        }

        if (bccomp((string) $line['quantity'], '0', 3) <= 0) {
            return false;
        }

        if (bccomp($deal['deal_price'], bcmul((string) $deal['deal_quantity'], $deal['unit_price'], 2), 2) >= 0) {
            return false;
        }

        $price = (string) $line['price'];

        return bccomp($price, $deal['unit_price'], 2) === 0
            || bccomp($price, self::deal_unit_price($deal['deal_quantity'], $deal['deal_price']), 2) === 0;
    }
}
```

Note: `testThreeMilkBecomesDealLineAndNormalLine` expects the normal line's `price` to be exactly `'7.00'` (the `unit_price` string passed in `$deals`) and the deal line's `'6.50'`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --no-coverage`
Expected: all pass (52 existing + the new Deal_pricing tests).

- [ ] **Step 5: Coding standard and commit**

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.no-header.php app/Libraries/Deal_pricing.php tests/Libraries/Deal_pricingTest.php
git add app/Libraries/Deal_pricing.php tests/Libraries/Deal_pricingTest.php
git commit -m "Add Deal_pricing library for multi-buy deals

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Store and edit deals on items

**Files:**
- Create: `app/Database/Migrations/sqlscripts/3.4.4_item_deals.sql`
- Create: `app/Database/Migrations/20261012000000_ItemDeals.php`
- Modify: `app/Models/Item.php` (`$allowedFields`, new `get_deals()`)
- Modify: `app/Controllers/Items.php` (`postSave()`, ~line 606)
- Modify: `app/Views/items/form.php` (after the Unit Price group, ~line 218)
- Modify: `app/Language/en/Items.php`

**Interfaces:**
- Consumes: `Deal_pricing::validate_deal(string $quantity, string $price, string $unit_price): ?string` (Task 1).
- Produces:
  - DB columns `ospos_items.deal_quantity`, `ospos_items.deal_price`.
  - `Item::get_deals(array $item_ids): array` → `item_id(int) => ['deal_quantity' => int, 'deal_price' => string, 'unit_price' => string]`, only items whose `deal_quantity` is not NULL.
  - Language keys `Items.deal_quantity`, `Items.deal_price`, `Items.deal_help`, `Items.deal_both_required`, `Items.deal_quantity_invalid`, `Items.deal_price_invalid`, `Items.deal_price_cents`.

- [ ] **Step 1: Migration**

`app/Database/Migrations/sqlscripts/3.4.4_item_deals.sql`:

```sql
ALTER TABLE `ospos_items`
  ADD COLUMN `deal_quantity` INT(10) NULL DEFAULT NULL,
  ADD COLUMN `deal_price` DECIMAL(15,2) NULL DEFAULT NULL;
```

`app/Database/Migrations/20261012000000_ItemDeals.php`:

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class Migration_ItemDeals extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');

        if (! executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/3.4.4_item_deals.sql')) {
            throw new RuntimeException('Migration ItemDeals failed: could not add deal columns to items.');
        }
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        $items = $this->db->prefixTable('items');

        $this->db->query("ALTER TABLE `{$items}` DROP COLUMN `deal_quantity`, DROP COLUMN `deal_price`");
    }
}
```

Run and verify (this is the only allowed schema change):

```bash
php spark migrate:status | tail -3
php spark migrate
mysql -uadmin -ppointofsale ospos -e "SHOW COLUMNS FROM ospos_items WHERE Field IN ('deal_quantity','deal_price')"
```
Expected: two rows, both `YES` nullable, default NULL. (Do not test rollback against the live DB; `down()` is reviewed by reading.)

- [ ] **Step 2: Item model**

Add `'deal_quantity'` and `'deal_price'` to `Item::$allowedFields` (after `'hsn_code'`). Add this method after `get_multiple_info()`:

```php
    /**
     * Gets the multi-buy deal settings of the given items that have a deal.
     *
     * @return array item_id => ['deal_quantity' => int, 'deal_price' => string, 'unit_price' => string]
     */
    public function get_deals(array $item_ids): array
    {
        if ($item_ids === []) {
            return [];
        }

        $builder = $this->db->table('items');
        $builder->select('item_id, deal_quantity, deal_price, unit_price');
        $builder->whereIn('item_id', $item_ids);
        $builder->where('deal_quantity IS NOT NULL');

        $deals = [];

        foreach ($builder->get()->getResultArray() as $row) {
            $deals[(int) $row['item_id']] = [
                'deal_quantity' => (int) $row['deal_quantity'],
                'deal_price'    => (string) $row['deal_price'],
                'unit_price'    => (string) $row['unit_price'],
            ];
        }

        return $deals;
    }
```

- [ ] **Step 3: Language strings** — add to `app/Language/en/Items.php` (keep alignment and alphabetical position):

```php
    "deal_both_required"                 => "Enter both a deal quantity and a deal price, or leave both empty.",
    "deal_help"                          => "Optional. Sell this many for this total price, e.g. 2 for 13.00.",
    "deal_price"                         => "Deal Price",
    "deal_price_cents"                   => "The deal price must divide into whole cents per item, e.g. 2 for 13.00 = 6.50 each.",
    "deal_price_invalid"                 => "The deal price must be more than zero and less than buying the same quantity at the normal price.",
    "deal_quantity"                      => "Deal Quantity",
    "deal_quantity_invalid"              => "The deal quantity must be a whole number of 2 or more.",
```

- [ ] **Step 4: Item form fields**

In `app/Views/items/form.php`, directly after the closing `</div>` of the Unit Price `form-group` (the block containing `'name'    => 'unit_price'`) and before `<?php if (!$use_destination_based_tax) { ?>`, add:

```php
        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.deal_quantity'), 'deal_quantity', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-2">
                <?= form_input([
                    'name'  => 'deal_quantity',
                    'id'    => 'deal_quantity',
                    'class' => 'form-control input-sm',
                    'value' => $item_info->deal_quantity ?? ''
                ]) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.deal_price'), 'deal_price', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <div class="input-group input-group-sm">
                    <?php if (!is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'  => 'deal_price',
                        'id'    => 'deal_price',
                        'class' => 'form-control input-sm',
                        'value' => isset($item_info->deal_price) ? to_currency_no_money($item_info->deal_price) : ''
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
                <span class="help-block"><?= lang('Items.deal_help') ?></span>
            </div>
        </div>
```

- [ ] **Step 5: Validate and save in `Items::postSave()`**

Add `use App\Libraries\Deal_pricing;` with the other imports. Directly after the line `$unit_price = parse_decimals($this->request->getPost('unit_price'));` insert:

```php
        $deal_quantity = trim((string) $this->request->getPost('deal_quantity'));
        $deal_price_input = trim((string) $this->request->getPost('deal_price'));
        $deal_price = $deal_price_input === '' ? '' : parse_decimals($deal_price_input);
        $deal_error = Deal_pricing::validate_deal(
            $deal_quantity,
            $deal_price === false ? 'invalid' : (string) $deal_price,
            $unit_price === false ? 'invalid' : (string) $unit_price
        );

        if ($deal_error !== null) {
            echo json_encode(['success' => false, 'message' => lang($deal_error), 'id' => $item_id]);

            return;
        }
```

In the `$item_data` array add (after `'unit_price' => $unit_price,`; re-align `=>` if the fixer asks):

```php
            'deal_quantity'         => $deal_quantity === '' ? null : (int) $deal_quantity,
            'deal_price'            => $deal_quantity === '' ? null : $deal_price,
```

- [ ] **Step 6: Checks**

```bash
vendor/bin/phpunit --no-coverage
php -l app/Controllers/Items.php && php -l app/Views/items/form.php && php -l app/Models/Item.php
vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.no-header.php app/Database/Migrations/20261012000000_ItemDeals.php
```

Manual (app via `php spark serve --port 8094` in the background, stop afterwards; curl with a cookie jar as `admin` / `pointofsale`; login has a honeypot, no CSRF token — POST username/password directly, then GET `/home`; item forms are CSRF-protected — fetch `items/view/<id>` and post its token back):
1. Open `items/view/<Milk item_id>` — the HTML contains the Deal Quantity and Deal Price inputs (empty).
2. POST `items/save/<Milk id>` with the form's current values plus `deal_quantity=2`, `deal_price=13.00` → JSON `success: true`; `SELECT deal_quantity, deal_price FROM ospos_items WHERE item_id=<id>` → `2 | 13.00`.
3. Same with `deal_quantity=3`, `deal_price=10.00` → `success: false` with the "whole cents" message; DB unchanged.
4. Same with both blank → `success: true`, both columns NULL. Then set 2 / 13.00 again (Task 3 needs it).
Record exact requests and results; mark anything you can't perform "not verified".

- [ ] **Step 7: Commit**

```bash
git add app/Database/Migrations/sqlscripts/3.4.4_item_deals.sql app/Database/Migrations/20261012000000_ItemDeals.php app/Models/Item.php app/Controllers/Items.php app/Views/items/form.php app/Language/en/Items.php
git commit -m "Store and edit multi-buy deals on items

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Apply deals at the register

**Files:**
- Modify: `app/Libraries/Sale_lib.php` (`add_item()` new-line array ~line 1074, `edit_item()` ~line 1189, new `apply_deals()`)
- Modify: `app/Controllers/Sales.php` (`_reload()`, `postComplete()`, `postSuspend()`)

**Interfaces:**
- Consumes: `Deal_pricing::apply(array $cart, array $deals): array` (Task 1); `Item::get_deals(array $item_ids): array` (Task 2); existing `Sale_lib::get_item_total(string $quantity, string $price, string $discount, int $discount_type, bool $include_discount = false): string`, `Sale_lib::is_return_mode(): bool`, `get_cart()`, `set_cart()`.
- Produces: `Sale_lib::apply_deals(): void`; cart line flags `from_kit` (bool) and `deal_excluded` (bool).

- [ ] **Step 1: Mark kit lines** — in `Sale_lib::add_item()`, in the new-line array (the one starting `$insertkey => [` with `'item_id' => $item_id,`), add after `'tax_category_id' => $item_info->tax_category_id`:

```php
                    'from_kit'              => $price_mode == PRICE_MODE_KIT,
```
(Add the comma after the previous entry; keep alignment.)

- [ ] **Step 2: Mark hand-edited lines** — in `Sale_lib::edit_item()`, inside `if (isset($items[$line])) {` right after `$line = &$items[$line];`, add:

```php
            if (($price !== null && bccomp((string) $price, (string) $line['price'], 2) !== 0)
                || bccomp((string) $discount, (string) $line['discount'], 2) !== 0) {
                $line['deal_excluded'] = true;
            }
```

- [ ] **Step 3: `apply_deals()`** — add to `Sale_lib` (after `edit_item()`), plus `use App\Libraries\Deal_pricing;` is not needed (same namespace) — call it as `Deal_pricing::apply(...)`:

```php
    /**
     * Re-prices cart lines of items that have a multi-buy deal. Does nothing in return mode.
     */
    public function apply_deals(): void
    {
        if ($this->is_return_mode()) {
            return;
        }

        $cart = $this->get_cart();

        if ($cart === []) {
            return;
        }

        $deals = $this->item->get_deals(array_values(array_unique(array_map('intval', array_column($cart, 'item_id')))));

        if ($deals === []) {
            return;
        }

        $cart = Deal_pricing::apply($cart, $deals);

        foreach ($cart as &$line) {
            if (isset($deals[$line['item_id']])) {
                $quantity = (string) $line['quantity'];
                $price = (string) $line['price'];
                $discount = (string) $line['discount'];
                $discount_type = (int) $line['discount_type'];

                $line['total'] = $this->get_item_total($quantity, $price, $discount, $discount_type);
                $line['discounted_total'] = $this->get_item_total($quantity, $price, $discount, $discount_type, true);
            }
        }
        unset($line);

        $this->set_cart($cart);
    }
```

Confirm `$this->item` is the `Item` model property in `Sale_lib` (it is assigned in the constructor: `$this->item = model(Item::class);`).

- [ ] **Step 4: Call it from the controller** — in `app/Controllers/Sales.php`:
- `_reload()`: directly before `$data['cart'] = $this->sale_lib->get_cart();` add `$this->sale_lib->apply_deals();`
- `postComplete()`: directly before its `$data['cart'] = $this->sale_lib->get_cart();` add `$this->sale_lib->apply_deals();`
- `postSuspend()`: directly before `$cart = $this->sale_lib->get_cart();` add `$this->sale_lib->apply_deals();`

Run: `grep -c "apply_deals()" app/Controllers/Sales.php` → `3`.

- [ ] **Step 5: Checks**

```bash
vendor/bin/phpunit --no-coverage
php -l app/Libraries/Sale_lib.php && php -l app/Controllers/Sales.php
```

Manual (app via `php spark serve --port 8094`, curl as `cashier1` / `cashierpass1` unless stated; Milk has deal 2 for 13.00 from Task 2; `item=<Milk item_id>`; read totals from the register HTML or from read-only SELECTs after completing):
1. Scan Milk once → register shows one Milk line 7.00. Scan again → one line ×2 @ 6.50 (13.00). Scan a third time → ×2 @ 6.50 and ×1 @ 7.00 (20.00). Fourth → ×4 @ 6.50 (26.00).
2. Edit the quantity of the 6.50 line from 4 to 3 via `sales/editItem/<line>` (post the line's current price and discount unchanged) → ×2 @ 6.50 + ×1 @ 7.00.
3. Delete the 7.00 line → ×2 @ 6.50.
4. Add a cash payment of 20.00 with 3 Milk, Complete → `SELECT quantity_purchased, item_unit_price, discount FROM ospos_sales_items WHERE sale_id=<new>` shows `2 | 6.50 | 0` and `1 | 7.00 | 0`.
5. Suspend a cart with 3 Milk, then unsuspend it → same two lines.
6. Change mode to Return, enter the receipt `POS <sale from step 4>` → lines keep 6.50 and 7.00 (refund 20.00). Then a new Return-mode cart with Milk typed by hand once → 7.00 (no deal). Complete returns as `admin` (has Approve Returns) to avoid the approval popup.
7. In Sale mode with 3 Milk, edit the 7.00 line's price to 6.00 → that line stays at 6.00 and is not regrouped; the 6.50 line stays.
Only use the test data this creates. Record requests and results; mark anything not performed "not verified".

- [ ] **Step 6: Commit**

```bash
git add app/Libraries/Sale_lib.php app/Controllers/Sales.php
git commit -m "Apply multi-buy deals at the register

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
