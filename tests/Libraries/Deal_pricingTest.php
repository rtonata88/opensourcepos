<?php

use App\Libraries\Deal_pricing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class Deal_pricingTest extends TestCase
{
    private const MILK  = 3;
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

    public function testPriceWithInvalidFormatIsRejected(): void
    {
        $this->assertSame('Items.deal_price_invalid', Deal_pricing::validate_deal('2', '1e2', '7.00'));
        $this->assertSame('Items.deal_price_invalid', Deal_pricing::validate_deal('2', ' 13', '7.00'));
        $this->assertSame('Items.deal_price_invalid', Deal_pricing::validate_deal('2', '13 ', '7.00'));
        $this->assertSame('Items.deal_price_invalid', Deal_pricing::validate_deal('2', '13.00', '7.00 '));
    }

    public function testPriceTruncationCheckedWithBcmath(): void
    {
        $this->assertSame('Items.deal_price_cents', Deal_pricing::validate_deal('5', '10.01', '3.00'));
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
            $this->assertSame($deal, (float) $split['deal'], "deal part of {$quantity}");
            $this->assertSame($normal, (float) $split['normal'], "normal part of {$quantity}");
        }
    }

    // apply

    public function testMilkTotalsForOneToFour(): void
    {
        foreach (['1' => 7.0, '2' => 13.0, '3' => 20.0, '4' => 26.0] as $quantity => $expected) {
            $cart = Deal_pricing::apply($this->line(1, self::MILK, (string) $quantity, '7.00'), $this->deals());
            $this->assertSame($expected, $this->total($cart), "total for {$quantity} milk");
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

    public function testThreeScansAreConsolidated(): void
    {
        $cart = $this->line(1, self::MILK, '1', '7.00') + $this->line(2, self::MILK, '1', '6.50') + $this->line(3, self::MILK, '1', '7.00');

        $cart = Deal_pricing::apply($cart, $this->deals());

        $this->assertSame([1, 2], array_keys($cart));
        $this->assertSame(2.0, (float) $cart[1]['quantity']);
        $this->assertSame('6.50', $cart[1]['price']);
        $this->assertSame(1.0, (float) $cart[2]['quantity']);
        $this->assertSame('7.00', $cart[2]['price']);
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
