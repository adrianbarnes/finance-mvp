<?php

namespace App\Enums;

enum Intent: string
{
    case CheckBalance = 'check_balance';
    case ApplyLoan = 'apply_loan';
    case ApplicationStatus = 'application_status';
    case RepaymentDue = 'repayment_due';
    case SpeakToAgent = 'speak_to_agent';
    case Yes = 'yes';
    case No = 'no';
    case Goodbye = 'goodbye';
    case Repeat = 'repeat';
    case Unknown = 'unknown';

    /**
     * Intents that start a self-service action from the main menu.
     *
     * @return list<self>
     */
    public static function menuActions(): array
    {
        return [self::CheckBalance, self::ApplyLoan, self::ApplicationStatus, self::RepaymentDue, self::SpeakToAgent];
    }
}
