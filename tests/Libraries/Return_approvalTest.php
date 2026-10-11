<?php

use App\Libraries\Return_approval;
use App\Models\Employee;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class Return_approvalTest extends TestCase
{
    /**
     * Builds a cart shaped like Sale_lib::get_cart() with the given line quantities.
     */
    private function cart(array $quantities): array
    {
        $cart = [];

        foreach ($quantities as $index => $quantity) {
            $line        = $index + 1;
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

        $this->assertFalse($approval->requires_approval($this->cart([2]), [], 7));
    }

    public function testApprovalNeededForCashierWithoutPermission(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->expects($this->once())
            ->method('has_grant')
            ->with('sales_returns', 7)
            ->willReturn(false);

        $approval = new Return_approval($employee);

        $this->assertTrue($approval->requires_approval($this->cart([-1]), [], 7));
    }

    public function testNoApprovalNeededForCashierWithPermission(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->method('has_grant')->with('sales_returns', 7)->willReturn(true);

        $approval = new Return_approval($employee);

        $this->assertFalse($approval->requires_approval($this->cart([-1]), [], 7));
    }

    public function testNegativeCashPaymentOnPositiveCartNeedsApproval(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->expects($this->once())->method('has_grant')->with('sales_returns', 7)->willReturn(false);
        $payments = [
            'Cash' => ['payment_type' => 'Cash', 'payment_amount' => '-500.00'],
            'Due'  => ['payment_type' => 'Due', 'payment_amount' => '501.00'],
        ];

        $approval = new Return_approval($employee);

        $this->assertTrue($approval->requires_approval($this->cart([1]), $payments, 7));
    }

    public function testPositivePaymentsOnPositiveCartNeedNoApproval(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->expects($this->never())->method('has_grant');
        $payments = ['Cash' => ['payment_type' => 'Cash', 'payment_amount' => '5.00']];

        $approval = new Return_approval($employee);

        $this->assertFalse($approval->requires_approval($this->cart([1]), $payments, 7));
    }

    public function testNegativePaymentWithGrantNeedsNoApproval(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->method('has_grant')->with('sales_returns', 7)->willReturn(true);
        $payments = ['Cash' => ['payment_type' => 'Cash', 'payment_amount' => '-5.00']];

        $approval = new Return_approval($employee);

        $this->assertFalse($approval->requires_approval($this->cart([1]), $payments, 7));
    }

    public function testPaymentsPayOut(): void
    {
        $this->assertTrue(Return_approval::payments_pay_out([['payment_type' => 'Cash', 'payment_amount' => '-0.01']]));
        $this->assertFalse(Return_approval::payments_pay_out([['payment_type' => 'Cash', 'payment_amount' => '0.00'], ['payment_type' => 'Due', 'payment_amount' => '3']]));
        $this->assertFalse(Return_approval::payments_pay_out([]));
    }

    public function testNegativeCashAloneDoesNotRequireSuspendApproval(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->expects($this->never())->method('has_grant');
        $payments = ['Cash' => ['payment_type' => 'Cash', 'payment_amount' => '-5.00']];

        $approval = new Return_approval($employee);

        $this->assertFalse($approval->requires_approval_to_suspend($this->cart([1]), $payments, 7, ['Gift Card', 'Rewards']));
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

    public function testNegativeLineTotalIsAReturn(): void
    {
        $cart = [1 => ['item_id' => 1, 'line' => 1, 'quantity' => 1, 'discounted_total' => '-5.00']];

        $this->assertTrue(Return_approval::cart_has_returns($cart));
    }

    public function testDiscountAbovePriceIsAReturn(): void
    {
        // 150% discount (PERCENT = 0) on a 10.00 item: total -5.00
        $cart = [1 => [
            'item_id'  => 1, 'line' => 1, 'quantity' => 1, 'price' => '10.00',
            'discount' => '150', 'discount_type' => 0, 'discounted_total' => '-5.00',
        ]];

        $this->assertTrue(Return_approval::cart_has_returns($cart));
    }

    public function testPositiveLineTotalIsNotAReturn(): void
    {
        $cart = [1 => ['item_id' => 1, 'line' => 1, 'quantity' => 2, 'discounted_total' => '18.00']];

        $this->assertFalse(Return_approval::cart_has_returns($cart));
    }

    public function testGiftcardPaymentCreditsAnAccount(): void
    {
        $payments = ['Gift Card:1001' => ['payment_type' => 'Gift Card:1001', 'payment_amount' => '-5.00']];

        $this->assertTrue(Return_approval::payments_credit_accounts($payments, ['Gift Card', 'Rewards']));
    }

    public function testRewardsPaymentCreditsAnAccount(): void
    {
        $payments = ['Rewards' => ['payment_type' => 'Rewards', 'payment_amount' => '-5.00']];

        $this->assertTrue(Return_approval::payments_credit_accounts($payments, ['Gift Card', 'Rewards']));
    }

    public function testCashPaymentDoesNotCreditAnAccount(): void
    {
        $payments = ['Cash' => ['payment_type' => 'Cash', 'payment_amount' => '-5.00']];

        $this->assertFalse(Return_approval::payments_credit_accounts($payments, ['Gift Card', 'Rewards']));
    }

    public function testSuspendNeedsApprovalForReturnPaidToGiftcard(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->method('has_grant')->with('sales_returns', 7)->willReturn(false);
        $payments = ['Gift Card:1001' => ['payment_type' => 'Gift Card:1001', 'payment_amount' => '-5.00']];

        $approval = new Return_approval($employee);

        $this->assertTrue($approval->requires_approval_to_suspend($this->cart([-1]), $payments, 7, ['Gift Card', 'Rewards']));
    }

    public function testSuspendWithoutAccountPaymentNeedsNoApproval(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->expects($this->never())->method('has_grant');
        $payments = ['Cash' => ['payment_type' => 'Cash', 'payment_amount' => '-5.00']];

        $approval = new Return_approval($employee);

        $this->assertFalse($approval->requires_approval_to_suspend($this->cart([-1]), $payments, 7, ['Gift Card', 'Rewards']));
    }

    public function testSuspendWithGiftcardButNoReturnsNeedsNoApproval(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->expects($this->never())->method('has_grant');
        $payments = ['Gift Card:1001' => ['payment_type' => 'Gift Card:1001', 'payment_amount' => '5.00']];

        $approval = new Return_approval($employee);

        $this->assertFalse($approval->requires_approval_to_suspend($this->cart([1]), $payments, 7, ['Gift Card', 'Rewards']));
    }

    public function testSuspendNeedsApprovalForOverpaymentToGiftcard(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->method('has_grant')->with('sales_returns', 7)->willReturn(false);
        $payments = ['Gift Card:1001' => ['payment_type' => 'Gift Card:1001', 'payment_amount' => '-50.00']];

        $approval = new Return_approval($employee);

        $this->assertTrue($approval->requires_approval_to_suspend($this->cart([1]), $payments, 7, ['Gift Card', 'Rewards']));
    }

    public function testSuspendWithPositiveGiftcardPaymentAndNoReturnsNeedsNoApprovalEvenWithoutGrant(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->expects($this->never())->method('has_grant');
        $payments = ['Gift Card:1001' => ['payment_type' => 'Gift Card:1001', 'payment_amount' => '5.00']];

        $approval = new Return_approval($employee);

        $this->assertFalse($approval->requires_approval_to_suspend($this->cart([1]), $payments, 7, ['Gift Card', 'Rewards']));
    }

    public function testSuspendWithGrantNeedsNoApproval(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->method('has_grant')->with('sales_returns', 7)->willReturn(true);
        $payments = ['Gift Card:1001' => ['payment_type' => 'Gift Card:1001', 'payment_amount' => '-5.00']];

        $approval = new Return_approval($employee);

        $this->assertFalse($approval->requires_approval_to_suspend($this->cart([-1]), $payments, 7, ['Gift Card', 'Rewards']));
    }

    public function testAccountPaymentsPayOutForNegativeGiftcard(): void
    {
        $payments = ['Gift Card:1001' => ['payment_type' => 'Gift Card:1001', 'payment_amount' => '-5.00']];

        $this->assertTrue(Return_approval::account_payments_pay_out($payments, ['Gift Card', 'Rewards']));
    }

    public function testAccountPaymentsDoNotPayOutForNegativeCash(): void
    {
        $payments = ['Cash' => ['payment_type' => 'Cash', 'payment_amount' => '-5.00']];

        $this->assertFalse(Return_approval::account_payments_pay_out($payments, ['Gift Card', 'Rewards']));
    }

    public function testAccountPaymentsDoNotPayOutForPositiveGiftcard(): void
    {
        $payments = ['Gift Card:1001' => ['payment_type' => 'Gift Card:1001', 'payment_amount' => '5.00']];

        $this->assertFalse(Return_approval::account_payments_pay_out($payments, ['Gift Card', 'Rewards']));
    }

    private function storedPayments(): array
    {
        return [
            ['payment_id' => '11', 'payment_type' => 'Cash', 'payment_amount' => '20.00', 'cash_refund' => '0.00'],
        ];
    }

    private function editedPayment(int $id, string $type, string $amount, string $refund = '0.00'): array
    {
        return ['payment_id' => $id, 'payment_type' => $type, 'payment_amount' => $amount, 'cash_refund' => $refund, 'cash_adjustment' => 0, 'employee_id' => 3];
    }

    public function testUnchangedPaymentsDoNotPayOut(): void
    {
        $this->assertFalse(Return_approval::payment_edits_pay_out($this->storedPayments(), [$this->editedPayment(11, 'Cash', '20.00')]));
    }

    public function testChangingOnlyPaymentTypeDoesNotPayOut(): void
    {
        $this->assertFalse(Return_approval::payment_edits_pay_out($this->storedPayments(), [$this->editedPayment(11, 'Debit Card', '20')]));
    }

    public function testChangedAmountPaysOut(): void
    {
        $this->assertTrue(Return_approval::payment_edits_pay_out($this->storedPayments(), [$this->editedPayment(11, 'Cash', '5.00')]));
    }

    public function testChangedRefundPaysOut(): void
    {
        $this->assertTrue(Return_approval::payment_edits_pay_out($this->storedPayments(), [$this->editedPayment(11, 'Cash', '20.00', '15.00')]));
    }

    public function testZeroingAPaymentPaysOut(): void
    {
        $this->assertTrue(Return_approval::payment_edits_pay_out($this->storedPayments(), [$this->editedPayment(11, 'Cash', '0')]));
    }

    public function testNewNegativePaymentPaysOut(): void
    {
        $edited = [$this->editedPayment(11, 'Cash', '20.00'), $this->editedPayment(-1, 'Cash', '-50.00')];

        $this->assertTrue(Return_approval::payment_edits_pay_out($this->storedPayments(), $edited));
    }

    public function testNewPositivePaymentDoesNotPayOut(): void
    {
        $edited = [$this->editedPayment(11, 'Cash', '20.00'), $this->editedPayment(-1, 'Cash', '5.00')];

        $this->assertFalse(Return_approval::payment_edits_pay_out($this->storedPayments(), $edited));
    }

    public function testForeignPaymentIdPaysOut(): void
    {
        $this->assertTrue(Return_approval::payment_edits_pay_out($this->storedPayments(), [$this->editedPayment(99, 'Cash', '20.00')]));
    }

    public function testEditNeedsApprovalWithoutPermission(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->expects($this->once())->method('has_grant')->with('sales_returns', 3)->willReturn(false);

        $approval = new Return_approval($employee);

        $this->assertTrue($approval->requires_approval_to_edit($this->storedPayments(), [$this->editedPayment(11, 'Cash', '5.00')], 3));
    }

    public function testEditAllowedWithPermission(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->method('has_grant')->with('sales_returns', 1)->willReturn(true);

        $approval = new Return_approval($employee);

        $this->assertFalse($approval->requires_approval_to_edit($this->storedPayments(), [$this->editedPayment(11, 'Cash', '5.00')], 1));
    }

    public function testHarmlessEditSkipsGrantLookup(): void
    {
        $employee = $this->createMock(Employee::class);
        $employee->expects($this->never())->method('has_grant');

        $approval = new Return_approval($employee);

        $this->assertFalse($approval->requires_approval_to_edit($this->storedPayments(), [$this->editedPayment(11, 'Debit Card', '20.00')], 3));
    }
}
