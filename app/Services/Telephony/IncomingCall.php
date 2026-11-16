<?php

namespace App\Services\Telephony;

use App\Enums\CallDirection;

/**
 * A call a provider has connected, in provider-neutral terms.
 */
final readonly class IncomingCall
{
    /**
     * @param  string  $callId  The provider's id for the call
     * @param  string  $customerNumber  The customer's number: the caller on a call
     *                                  coming in, the number rung on a call we placed
     */
    public function __construct(
        public string $callId,
        public string $customerNumber,
        public CallDirection $direction,
    ) {}
}
