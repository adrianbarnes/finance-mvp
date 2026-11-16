<?php

namespace App\Services\Telephony;

use RuntimeException;

/**
 * A provider would not ring a phone, e.g. a number not verified on a trial
 * account or a country the account may not call. The message says why.
 */
class CallNotPlaced extends RuntimeException {}
