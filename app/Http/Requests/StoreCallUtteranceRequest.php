<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCallUtteranceRequest extends FormRequest
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
        return [
            'audio' => [
                'required',
                'file',
                'max:5120',
                'mimetypes:audio/webm,video/webm,audio/wav,audio/x-wav,audio/wave,audio/vnd.wave,audio/mpeg,audio/ogg,audio/flac,audio/x-flac',
            ],
        ];
    }
}
