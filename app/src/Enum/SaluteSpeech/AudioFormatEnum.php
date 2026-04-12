<?php

namespace App\Enum\SaluteSpeech;

enum AudioFormatEnum: string
{
    case PCM_S16LE = 'audio/x-pcm;bit=16;rate=16000';
    case OPUS = 'audio/ogg;codecs=opus';
    case MP3 = 'audio/mpeg';
    case FLAC = 'audio/flac';
    case ALAW = 'audio/x-alaw-basic';
    case MULAW = 'audio/basic';
}
