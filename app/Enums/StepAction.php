<?php

namespace App\Enums;

enum StepAction: string
{
    case RegisterCaller = 'register_caller';
    case SubmitLoanApplication = 'submit_loan_application';

    public function label(): string
    {
        return match ($this) {
            self::RegisterCaller => 'Register the caller (uses the answer "name")',
            self::SubmitLoanApplication => 'Submit a loan application (uses "amount" and "months")',
        };
    }
}
