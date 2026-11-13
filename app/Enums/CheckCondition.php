<?php

namespace App\Enums;

/**
 * What a check step looks at. Its "yes" branch is taken when the
 * condition holds; otherwise the "otherwise" branch.
 */
enum CheckCondition: string
{
    case LanguageSaved = 'language_saved';
    case KnownCaller = 'known_caller';
    case HasApplication = 'has_application';
    case HasRepaymentDue = 'has_repayment_due';

    public function label(): string
    {
        return match ($this) {
            self::LanguageSaved => 'Caller has a saved language (and remembering is on)',
            self::KnownCaller => 'Caller is a known customer',
            self::HasApplication => 'Caller has a loan application',
            self::HasRepaymentDue => 'Caller has a repayment due',
        };
    }
}
