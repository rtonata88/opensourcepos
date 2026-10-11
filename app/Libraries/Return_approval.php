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
     * True when any cart line pays money out: a negative quantity, or a negative
     * line total (negative price, or a discount larger than the price).
     */
    public static function cart_has_returns(array $cart): bool
    {
        foreach ($cart as $item) {
            if ((float) $item['quantity'] < 0 || (float) ($item['discounted_total'] ?? 0) < 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when any payment goes to a stored-value account (gift card, rewards),
     * whose balance changes as soon as the sale is saved, even when suspended.
     *
     * @param array $account_payment_types payment type labels, matched as substrings
     */
    public static function payments_credit_accounts(array $payments, array $account_payment_types): bool
    {
        foreach ($payments as $payment) {
            foreach ($account_payment_types as $type) {
                if (str_contains($payment['payment_type'], $type)) {
                    return true;
                }
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
     * True when suspending would move money for a return without approval.
     */
    public function requires_approval_to_suspend(array $cart, array $payments, int $employee_id, array $account_payment_types): bool
    {
        return self::payments_credit_accounts($payments, $account_payment_types)
            && $this->requires_approval($cart, $employee_id);
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
