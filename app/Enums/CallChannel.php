<?php

namespace App\Enums;

enum CallChannel: string
{
    case WebRtc = 'webrtc';
    case Ivr = 'ivr';
}
