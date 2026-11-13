<?php

namespace App\Enums;

/**
 * How a caller picks a branch of a choice by speaking: with the branch's
 * own keywords, or with the language's yes or no words.
 */
enum BranchMatch: string
{
    case Keywords = 'keywords';
    case Yes = 'yes';
    case No = 'no';

    public function label(): string
    {
        return match ($this) {
            self::Keywords => 'Its own words',
            self::Yes => 'The language\'s yes words',
            self::No => 'The language\'s no words',
        };
    }
}
