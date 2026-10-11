# Multi-buy Deals ("N for X") — Design

**Date:** 2026-10-11
**Status:** Design approved in conversation; awaiting spec review

## Problem

Shops sell some items cheaper in groups: Milk is 7.00 each, or 2 for 13.00. OSPOS has no
quantity pricing. The Item Kit workaround fails: `Sale_lib::add_item()` merges every line of
the same item into one cart line (`Sale_lib.php:1032`), and a line has a single discount, so
a kit plus a single item either discounts all units or none.

## Goal

Each item can carry one optional deal, "N for X". The register applies it automatically,
however the items were scanned:

| Milk quantity | Total |
|---|---|
| 1 | 7.00 |
| 2 | 13.00 |
| 3 | 20.00 (one pair at 13 + one at 7) |
| 4 | 26.00 |

The deal repeats for every full group of N; leftovers are charged at the normal price.

### Agreed decisions

- One deal per item, on that item only (no tiers, no mix-and-match across items).
- **Approach A — split lines.** The cart holds a *deal line* and a *normal line* for the item:
  `Milk × 2 @ 6.50` and `Milk × 1 @ 7.00`. Totals, taxes, stock, receipts and reports keep
  working unchanged because both are ordinary lines.
- Deal price ÷ deal quantity must be a whole number of cents (2 for 13 → 6.50 ✓; 3 for 10 ✗).
- Returns refund what was paid: receipt-based returns keep the receipt's line prices; hand-typed
  returns in Return mode use the normal price. Deals never apply in Return mode.
- Deal lines carry no discount: the deal price is final. The normal line keeps whatever discount
  the item would otherwise get (e.g. a customer discount).

### Non-goals

- Several deals per item, best-combination pricing, mix-and-match deals.
- Date-limited deals, customer-specific deals.
- Deal fields in the items CSV import/export.
- Deals on item kit lines.
- Translations beyond `en`.

## Design

### 1. Data

Migration `20261012000000_ItemDeals` (script `sqlscripts/3.4.4_item_deals.sql`) adds to `ospos_items`:

```sql
ALTER TABLE `ospos_items`
  ADD COLUMN `deal_quantity` INT(10) NULL DEFAULT NULL,
  ADD COLUMN `deal_price` DECIMAL(15,2) NULL DEFAULT NULL;
```

Both NULL = no deal. `up()` throws `RuntimeException` if the script fails; `down()` drops both
columns. `Item::$allowedFields` gains both.

### 2. Item form (`app/Views/items/form.php`, `Items::postSave`)

Two optional fields after Unit Price: **Deal quantity** and **Deal price** (en labels
`Items.deal_quantity` "Deal Quantity", `Items.deal_price` "Deal Price", help text
`Items.deal_help` "Optional. Sell this many for this total price, e.g. 2 for 13.00.").

Validation in `Items::postSave` (server-side; on failure return the existing JSON error shape
with an en message):

- both blank → save NULL/NULL (no deal);
- exactly one filled → error `Items.deal_both_required`;
- quantity must be an integer ≥ 2 → `Items.deal_quantity_invalid`;
- price must be > 0 and < quantity × unit price → `Items.deal_price_invalid`;
- `round(price × 100)` must be divisible by quantity → `Items.deal_price_cents`
  ("The deal price must divide into whole cents per item, e.g. 2 for 13.00 = 6.50 each.").

The pure checks live in `Deal_pricing::validate_deal(?string $quantity, ?string $price, string $unit_price): ?string`
(returns the error language key or null) so they are unit-testable.

### 3. Pricing library — `App\Libraries\Deal_pricing`

Pure functions, no DB or session access:

- `deal_unit_price(int $deal_quantity, string $deal_price): string` — price ÷ quantity, 2 dp.
- `split_quantity(string $quantity, int $deal_quantity): array` → `['deal' => string, 'normal' => string]`.
  `deal` = floor(quantity ÷ N) × N, `normal` = quantity − deal. Fractional quantities: only whole
  groups count; the fractional remainder goes to `normal`. Quantities ≤ 0 → all `normal`.
- `apply(array $cart, array $deals, callable $next_line): array` — `$deals` maps item_id →
  `['deal_quantity' => int, 'deal_price' => string, 'unit_price' => string]`. For each item_id
  in `$deals`, the *managed* lines of that item (see §4) are replaced by at most two lines: a deal
  line (deal quantity, deal unit price, discount 0) and a normal line (remaining quantity, normal
  unit price, the normal discount — taken from the first managed line that had a non-deal price, else 0).
  Lines keep item_id, location, description, serial number and print option of the first managed line.
  Zero-quantity lines are dropped. `apply()` only sets quantity, price, discount and discount_type on
  the lines it builds; `Sale_lib::apply_deals()` then recomputes `total` and `discounted_total` for every
  line with its existing `get_item_total()`, so the formula stays in one place. Unmanaged lines
  and items without a deal are untouched. Line order is kept stable (new lines take the position
  of the first managed line). `$next_line` supplies new line keys.

### 4. Which lines are managed

A cart line is managed by the deal when all of:

- the cart is not in Return mode (Deal pricing is skipped entirely in Return mode);
- its quantity > 0;
- it was not added as part of an item kit. Cart lines do not record this today, so `add_item()` sets a
  new line flag `from_kit = true` when called with `PRICE_MODE_KIT`;
- the cashier has not changed its price or discount by hand. `Sale_lib::edit_item()` sets a line
  flag `deal_excluded = true` when the posted price or discount differs from the line's current
  values; quantity-only edits keep the line managed.

### 5. Where it runs

`Sale_lib::apply_deals(): void` loads deal settings for the item_ids in the cart (one query via
the Item model), calls `Deal_pricing::apply()`, and saves the cart with `set_cart()`. It does
nothing in Return mode or when no cart item has a deal.

Called from `Sales::_reload()` before the cart is read (every register action ends in
`_reload`), and at the start of `Sales::postComplete()` and `Sales::postSuspend()` so a sale is
never saved with stale deal lines.

`add_item()`'s existing merge-by-item_id behaviour is kept: merged quantity lands on one line and
`apply_deals()` re-splits it.

### 6. Interactions

- **Stock:** unchanged — both lines are the same item.
- **Taxes / receipts / invoices / reports:** unchanged — two ordinary lines.
- **Supervisor return approval:** deal lines are positive; no effect.
- **Suspend → unsuspend:** `copy_entire_sale()` re-adds stored lines; `apply_deals()` re-splits
  them to the same result.
- **Receipt-based returns:** Return mode → deals skipped → receipt prices kept.
- **Customer discount applied later** (`apply_customer_discount()`): applies to all lines; the next
  `apply_deals()` resets deal lines to discount 0.

## Testing

PHPUnit (mock-free; `Deal_pricing` is pure; no DB-backed tests):

- `split_quantity`: 1,2,3,4,5 with N=2; 2.5 with N=2; 0 and negative.
- `deal_unit_price`: 2 for 13.00 → 6.50; 4 for 25.00 → 6.25.
- `validate_deal`: blank/blank ok; one blank; quantity 1, 1.5, "abc"; price 0, price ≥ N×unit; 3 for 10 (cents).
- `apply`: Milk qty 1/2/3/4 → expected lines and totals (7.00 / 13.00 / 20.00 / 26.00); two separate
  Milk lines (merged scans) re-split; customer-discounted line → deal line discount 0, normal line keeps
  discount; `deal_excluded` line untouched; kit line untouched; item without deal untouched; stable order.

Manual (as a cashier): Milk with deal 2 for 13 — scan 1, 2, 3, 4 times and check totals; edit quantity
3 → 2; delete a line; suspend and resume; complete and check the receipt and the Detailed Sales report;
return the sale by receipt and check refund 20.00; hand-typed return of 1 Milk refunds 7.00; edit price
on a Milk line and check the deal leaves it alone.
