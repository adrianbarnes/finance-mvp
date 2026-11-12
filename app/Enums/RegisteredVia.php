<?php

namespace App\Enums;

/**
 * How a customer record was created.
 */
enum RegisteredVia: string
{
    case Panel = 'panel';
    case Voice = 'voice';
}
