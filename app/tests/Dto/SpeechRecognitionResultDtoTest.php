<?php

namespace App\Tests\Dto;

use App\Dto\SaluteSpeech\SpeechRecognitionResultDto;
use PHPUnit\Framework\TestCase;

class SpeechRecognitionResultDtoTest extends TestCase
{
    public function testGetTextReturnsFirstResult(): void
    {
        $dto = new SpeechRecognitionResultDto(['Первый', 'Второй'], [], 200);

        self::assertSame('Первый', $dto->getText());
    }

    public function testGetTextReturnsEmptyStringWhenNoResult(): void
    {
        $dto = new SpeechRecognitionResultDto([], [], 200);

        self::assertSame('', $dto->getText());
    }
}
