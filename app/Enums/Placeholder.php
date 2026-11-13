<?php

namespace App\Enums;

/**
 * Values spoken at call time inside a step's audio. Answers saved by
 * "ask" steps are referenced as "answer:{name}" instead.
 */
enum Placeholder: string
{
    case CustomerName = 'customer_name';
    case Balance = 'balance';
    case ApplicationAmount = 'application_amount';
    case ApplicationReference = 'application_reference';
    case ApplicationStatus = 'application_status';
    case RepaymentAmount = 'repayment_amount';
    case RepaymentDate = 'repayment_date';

    public function label(): string
    {
        return match ($this) {
            self::CustomerName => 'Customer name',
            self::Balance => 'Balance',
            self::ApplicationAmount => 'Application amount',
            self::ApplicationReference => 'Application reference',
            self::ApplicationStatus => 'Application status',
            self::RepaymentAmount => 'Repayment amount',
            self::RepaymentDate => 'Repayment date',
        };
    }

    /**
     * Readable label for a stored placeholder, including saved answers.
     */
    public static function describe(string $placeholder): string
    {
        if (str_starts_with($placeholder, 'answer:')) {
            return 'Answer: '.substr($placeholder, 7);
        }

        return self::tryFrom($placeholder)?->label() ?? $placeholder;
    }
}
