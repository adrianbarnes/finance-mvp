<?php

namespace App\Enums;

enum LoanApplicationStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Disbursed = 'disbursed';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::UnderReview => 'Under review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Disbursed => 'Disbursed',
            self::Closed => 'Closed',
        };
    }

    /**
     * Voice prompt key the assistant plays to describe this status.
     */
    public function promptKey(): string
    {
        return 'status_'.$this->value;
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'zinc',
            self::UnderReview => 'amber',
            self::Approved => 'green',
            self::Rejected => 'red',
            self::Disbursed => 'blue',
            self::Closed => 'zinc',
        };
    }
}
