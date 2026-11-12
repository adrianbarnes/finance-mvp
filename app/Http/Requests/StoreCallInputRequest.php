<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One caller input: keypad digits, typed text that stands in for speech
 * when testing without spending Khaya quota, a transcript of speech the
 * browser recognised itself (English), or a "no_input" event when the
 * caller stayed silent after a prompt.
 */
class StoreCallInputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $inputs = ['digits', 'text', 'transcript', 'event'];
        $others = fn (string $field): string => implode(',', array_diff($inputs, [$field]));

        return [
            'digits' => ['required_without_all:'.$others('digits'), 'prohibits:'.$others('digits'), 'string', 'regex:/^[0-9*#]{1,12}$/'],
            'text' => ['required_without_all:'.$others('text'), 'prohibits:'.$others('text'), 'string', 'max:500'],
            'transcript' => ['required_without_all:'.$others('transcript'), 'prohibits:'.$others('transcript'), 'string', 'max:500'],
            'event' => ['required_without_all:'.$others('event'), Rule::in(['no_input'])],
        ];
    }
}
