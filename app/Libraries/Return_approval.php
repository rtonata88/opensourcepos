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
            && ! $this->employee->has_grant(self::PERMISSION, $employee_id);
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

        if ($approver_id === null || ! $this->employee->has_grant(self::PERMISSION, $approver_id)) {
            return null;
        }

        return $approver_id;
    }
}
