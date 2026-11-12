<?php

namespace App\Enums;

/**
 * How the caller provided a turn's input.
 */
enum InputMode: string
{
    case Speech = 'speech';
    case Keypad = 'keypad';
    case Text = 'text';
    case NoInput = 'no_input';
}
