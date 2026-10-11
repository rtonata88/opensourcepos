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

        if (! preg_match('/^\d+(\.\d{1,2})?\z/', $price) || ! preg_match('/^\d+(\.\d{1,2})?\z/', $unit_price)
                                                         || bccomp($price, '0', 2) <= 0
                                                         || bccomp($price, bcmul($quantity, $unit_price, 2), 2) >= 0) {
            return 'Items.deal_price_invalid';
        }

        if (bcmod(bcmul($price, '100', 0), $quantity) !== '0') {
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
        $deal   = bcmul($groups, (string) $deal_quantity, 3);

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
        $result   = [];

        foreach ($cart as $key => $line) {
            $keys = $managed[$line['item_id']] ?? [];

            if (! in_array($key, $keys, true)) {
                $result[$key] = $line;

                continue;
            }

            if ($key !== $keys[0]) {
                continue;
            }

            $deal                 = $deals[$line['item_id']];
            $quantity             = '0';
            $normal_discount      = null;
            $normal_discount_type = $line['discount_type'];

            foreach ($keys as $managed_key) {
                $managed_line = $cart[$managed_key];
                $quantity     = bcadd($quantity, (string) $managed_line['quantity'], 3);

                if ($normal_discount === null && bccomp((string) $managed_line['discount'], '0', 2) !== 0) {
                    $normal_discount      = (string) $managed_line['discount'];
                    $normal_discount_type = $managed_line['discount_type'];
                }
            }

            if ($normal_discount === null) {
                foreach ($keys as $managed_key) {
                    if (isset($cart[$managed_key]['deal_normal_discount'])) {
                        $normal_discount      = (string) $cart[$managed_key]['deal_normal_discount'];
                        $normal_discount_type = $cart[$managed_key]['deal_normal_discount_type'];

                        break;
                    }
                }
            }

            $normal_discount ??= '0';
            $split      = self::split_quantity($quantity, $deal['deal_quantity']);
            $has_deal   = bccomp($split['deal'], '0', 3) > 0;
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
