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
