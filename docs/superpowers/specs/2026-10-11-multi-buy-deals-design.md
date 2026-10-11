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
  the item would otherwise get (e.g. a customer discount), unless that discount makes no-deal
  cheaper, in which case no-deal pricing is used (never worse than no deal).

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

The quantity must also be at most 1000 (`Items.deal_quantity_invalid`) so it fits the INT column.
The pure checks live in `Deal_pricing::validate_deal(string $quantity, string $price, string $unit_price): ?string`
(non-nullable strings, empty string meaning blank; returns the error language key or null) so they are unit-testable.

### 3. Pricing library — `App\Libraries\Deal_pricing`

Pure functions, no DB or session access:

- `deal_unit_price(int $deal_quantity, string $deal_price): string` — price ÷ quantity, 2 dp.
- `split_quantity(string $quantity, int $deal_quantity): array` → `['deal' => string, 'normal' => string]`.
  `deal` = floor(quantity ÷ N) × N, `normal` = quantity − deal. Fractional quantities: only whole
  groups count; the fractional remainder goes to `normal`. Quantities ≤ 0 → all `normal`.
- `discounted_total(string $quantity, string $price, string $discount, int $discount_type): string` —
  mirrors `Sale_lib::get_item_total(..., true)` / `get_item_discount()`: percent is quantity × price × discount / 100,
  fixed is quantity × discount, the discount rounded to 2 dp.
- `apply(array $cart, array $deals): array` — `$deals` maps item_id →
  `['deal_quantity' => int, 'deal_price' => string, 'unit_price' => string]`. Managed lines (see §4)
  are grouped by item and location (so stock is taken from the right location). Each group is
  replaced by at most two lines: a deal line (deal quantity, deal unit price, discount 0) and a normal
  line (remaining quantity, normal unit price, the normal discount). The normal discount is the first
  non-zero discount among the group's managed lines, else the `deal_normal_discount` stored on a deal line,
  else 0. The deal line stores it as `deal_normal_discount`/`deal_normal_discount_type` so it survives
  re-application. Lines keep item_id, location, description, serial number and print option of the first
  managed line; new line keys come from the highest cart key + 1. Lines with quantity <= 0 are not
  managed and are left untouched. `apply()` only sets quantity, price, discount and discount_type;
  `Sale_lib::apply_deals()` then recomputes `total` and `discounted_total` with `get_item_total()`.
  Unmanaged lines and items without a deal are untouched; line order is kept stable.
- **Never worse than no deal.** For each group, the baseline is all managed quantity at the unit price with the
  normal discount; the deal result is the deal line plus the normal line. If the deal result's discounted total
  is greater than the baseline (compared at 2 dp), the group becomes a single no-deal line at the unit
  price with the normal discount (first managed key reused, other managed lines dropped, no
  `deal_normal_discount`). The output is idempotent: applying again gives the same lines.

### 4. Which lines are managed

A cart line is managed by the deal when all of:

- the cart is not in Return mode (Deal pricing is skipped entirely in Return mode);
- its quantity > 0;
- it was not added as part of an item kit. Cart lines do not record this today, so `add_item()` sets a
  new line flag `from_kit = true` when called with `PRICE_MODE_KIT`;
- the cashier has not changed its price or discount by hand. `Sale_lib::edit_item()` sets a line
  flag `deal_excluded = true` when the posted price or discount differs from the line's current
  values; quantity-only edits keep the line managed.
- the item is not serialized (serialized items keep one line per serial number);
- its price equals the item's unit price or the deal unit price (lines priced some other way, e.g. a
  barcode price token, are left alone);
- the deal is still cheaper than buying at the unit price (if the unit price was lowered after the
  deal was set, the deal stops applying).

### 5. Where it runs

`Sale_lib::apply_deals(): void` loads deal settings for the item_ids in the cart (one query via
the Item model), calls `Deal_pricing::apply()`, and saves the cart with `set_cart()`. It does
nothing in Return mode or when no cart item has a deal.

Called from `Sales::_reload()` before the cart is read (every register action ends in
`_reload`), and at the start of `Sales::postComplete()` and `Sales::postSuspend()` so a sale is
never saved with stale deal lines.

`add_item()` still merges a scan into the last matching line (same item and location) and
`apply_deals()` re-splits it, with these rules: with a price override the target must also have the same price;
without one, lines flagged `deal_excluded` or `from_kit` are never the merge target, so the scan goes to
another matching line or a new line and still earns the deal. Cart numbers read back from the session
(floats, empty strings, exponent notation) are normalised by `Sale_lib::plain_decimal()` to plain decimal
strings before bcmath is used, and `apply_deals()` always passes explicit scales to bc functions.

### 6. Interactions

- **Stock:** unchanged — both lines are the same item.
- **Taxes / receipts / invoices / reports:** unchanged — two ordinary lines.
- **Supervisor return approval:** deal lines are positive; no effect.
- **Suspend → unsuspend:** `copy_entire_sale()` re-adds stored lines. Restored lines with a non-zero
  discount are flagged `deal_excluded`, so a restored sale keeps the exact discounted lines it was saved
  with, and while restoring, lines of a deal item with different discounts are kept separate instead of
  merged. Undiscounted lines stay managed and `apply_deals()` re-splits them to the same result. The
  add_item merge rules (skipping excluded/kit lines, separate discounts) apply only to items that have a deal.
- **Receipt-based returns:** Return mode → deals skipped → receipt prices kept.
- **Customer discount applied later** (`apply_customer_discount()`): applies to all lines; the next
  `apply_deals()` moves it to the normal line and resets deal lines to discount 0, unless that discount makes
  the deal dearer than no deal, in which case the item falls back to no-deal pricing (never worse than no deal).

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
