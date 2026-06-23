<?php

namespace App\Tests\Enum;

use App\Enum\SaluteSpeech\AudioFormatEnum;
use PHPUnit\Framework\TestCase;

class AudioFormatEnumTest extends TestCase
{
    public function testResolvesByCaseName(): void
    {
        self::assertSame(AudioFormatEnum::OPUS, AudioFormatEnum::tryFromNameOrValue('OPUS'));
    }

    public function testResolvesByValue(): void
    {
        self::assertSame(AudioFormatEnum::OPUS, AudioFormatEnum::tryFromNameOrValue('audio/ogg;codecs=opus'));
    }

    public function testReturnsNullForUnknown(): void
    {
        self::assertNull(AudioFormatEnum::tryFromNameOrValue('BOGUS'));
    }
}
