<?php
/**
 * @var string $transaction_time
 * @var int $sale_id
 * @var string $invoice_number
 * @var string $employee
 * @var array $cart
 * @var float $discount
 * @var float $prediscount_subtotal
 * @var float $subtotal
 * @var array $taxes
 * @var float $total
 * @var array $payments
 * @var float $amount_change
 * @var string $barcode
 * @var array $config
 */
?>

<div id="receipt_wrapper" style="font-size: <?= $config['receipt_font_size'] ?>px;">
    <div id="receipt_header">
        <?php if ($config['company_logo'] != '') { ?>
            <div id="company_name">
                <img id="image" src="<?= base_url('uploads/' . esc($config['company_logo'], 'url')) ?>" alt="company_logo">
            </div>
        <?php } ?>

        <?php if ($config['receipt_show_company_name']) { ?>
            <div id="company_name"><?= nl2br(esc($config['company'])) ?></div>
        <?php } ?>

        <div id="company_address"><?= nl2br(esc($config['address'])) ?></div>
        <div id="company_phone"><?= esc($config['phone']) ?></div>
        <div id="sale_receipt"><?= lang('Sales.receipt') ?></div>
        <div id="sale_time"><?= ($transaction_time) ?></div>
    </div>

    <div id="receipt_general_info">
        <?php if (isset($customer)) { ?>
            <div id="customer"><?= lang('Customers.customer') . esc(": $customer") ?></div>
        <?php } ?>

        <div id="sale_id"><?= lang('Sales.id') . esc(": $sale_id") ?></div>

        <?php if (!empty($invoice_number)) { ?>
            <div id="invoice_number"><?= lang('Sales.invoice_number') . esc(": $invoice_number") ?></div>
        <?php } ?>

        <div id="employee"><?= lang('Employees.employee') . esc(": $employee") ?></div>
    </div>

    <table id="receipt_items" style="width: 100%;">
        <tr>
            <th style="width: 33%; text-align: left;"><?= lang('Sales.quantity') ?></th>
            <th style="width: 33%; text-align: left;"><?= lang('Sales.price') ?></th>
            <th style="width: 34%; text-align: right;" class="total-value"><?= lang('Sales.total') ?></th>
        </tr>
        <tr>
            <td colspan="3" style="border-bottom: 1px dashed #000000;"></td>
        </tr>

        <?php
        foreach ($cart as $line => $item) {
            if ($item['print_option'] == PRINT_YES) {
        ?>
                <!-- Row 1: item description -->
                <tr>
                    <td colspan="3" style="font-weight: bold;"><?= esc(ucfirst($item['name'] . ' ' . $item['attribute_values'])) ?></td>
                </tr>

                <?php if ($config['receipt_show_description'] && !empty($item['description'])) { ?>
                    <tr>
                        <td colspan="3"><?= esc($item['description']) ?></td>
                    </tr>
                <?php } ?>

                <?php if ($config['receipt_show_serialnumber'] && !empty($item['serialnumber'])) { ?>
                    <tr>
                        <td colspan="3"><?= esc($item['serialnumber']) ?></td>
                    </tr>
                <?php } ?>

                <!-- Row 2: quantity, price, line total -->
                <tr>
                    <td style="text-align: left;"><?= to_quantity_decimals($item['quantity']) ?></td>
                    <td style="text-align: left;"><?= to_currency($item['price']) ?><?php if ($config['receipt_show_tax_ind']) { echo ' ' . esc($item['taxed_flag']); } ?></td>
                    <td class="total-value" style="text-align: right;"><?= to_currency($item[($config['receipt_show_total_discount'] ? 'total' : 'discounted_total')]) ?></td>
                </tr>

                <?php if ($item['discount'] > 0) { ?>
                    <tr>
                        <td colspan="2" class="discount">
                            <?php if ($item['discount_type'] == FIXED) {
                                echo to_currency($item['discount']) . " " . lang('Sales.discount');
                            } elseif ($item['discount_type'] == PERCENT) {
                                echo to_decimals($item['discount']) . " " . lang('Sales.discount_included');
                            } ?>
                        </td>
                        <td class="total-value" style="text-align: right;"><?= to_currency($item['discounted_total']) ?></td>
                    </tr>
                <?php } ?>

                <!-- Separator between items -->
                <tr>
                    <td colspan="3" style="border-bottom: 1px dashed #000000;"></td>
                </tr>
        <?php
            }
        }
        ?>

        <?php if ($config['receipt_show_total_discount'] && $discount > 0) { ?>
            <tr>
                <td colspan="2" style="text-align: right;"><?= lang('Sales.sub_total') ?></td>
                <td style="text-align: right;"><?= to_currency($prediscount_subtotal) ?></td>
            </tr>
            <tr>
                <td colspan="2" class="total-value"><?= lang('Sales.customer_discount') ?>:</td>
                <td class="total-value" style="text-align: right;"><?= to_currency($discount * -1) ?></td>
            </tr>
        <?php } ?>

        <?php if ($config['receipt_show_taxes']) { ?>
            <tr>
                <td colspan="2" style="text-align: right;"><?= lang('Sales.sub_total') ?></td>
                <td style="text-align: right;"><?= to_currency($subtotal) ?></td>
            </tr>
            <?php foreach ($taxes as $tax_group_index => $tax) { ?>
                <tr>
                    <td colspan="2" class="total-value"><?= (float)$tax['tax_rate'] . '% ' . esc($tax['tax_group']) ?>:</td>
                    <td class="total-value" style="text-align: right;"><?= to_currency_tax($tax['sale_tax_amount']) ?></td>
                </tr>
            <?php }
        } ?>

        <tr></tr>

        <tr>
            <td colspan="2" style="text-align: right; font-weight: bold; border-top: 2px solid #000000;"><?= lang('Sales.total') ?></td>
            <td style="text-align: right; font-weight: bold; border-top: 2px solid #000000;"><?= to_currency($total) ?></td>
        </tr>

        <tr>
            <td colspan="3">&nbsp;</td>
        </tr>

        <?php
        $only_sale_check = false;
        $show_giftcard_remainder = false;
        foreach ($payments as $payment_id => $payment) {
            $only_sale_check |= $payment['payment_type'] == lang('Sales.check');
            $splitpayment = explode(':', $payment['payment_type']);    // TODO: The variable splitpayment does not follow naming conventions for this project
            $show_giftcard_remainder |= $splitpayment[0] == lang('Sales.giftcard');
        ?>
            <tr>
                <td colspan="2" style="text-align: right;"><?= esc($splitpayment[0]) ?> </td>
                <td class="total-value" style="text-align: right;"><?= to_currency($payment['payment_amount'] * -1) ?></td>
            </tr>
        <?php } ?>

        <tr>
            <td colspan="3">&nbsp;</td>
        </tr>

        <?php if (isset($cur_giftcard_value) && $show_giftcard_remainder) { ?>
            <tr>
                <td colspan="2" style="text-align: right;"><?= lang('Sales.giftcard_balance') ?></td>
                <td class="total-value" style="text-align: right;"><?= to_currency($cur_giftcard_value) ?></td>
            </tr>
        <?php } ?>
        <tr>
            <td colspan="2" style="text-align: right;"> <?= lang($amount_change >= 0 ? ($only_sale_check ? 'Sales.check_balance' : 'Sales.change_due') : 'Sales.amount_due') ?> </td>
            <td class="total-value" style="text-align: right;"><?= to_currency($amount_change) ?></td>
        </tr>
    </table>

    <div id="sale_return_policy">
        <?= nl2br(esc($config['return_policy'])) ?>
    </div>

    <div id="barcode">
        <?= $barcode ?><br>
        <?= $sale_id ?>
    </div>
</div>
